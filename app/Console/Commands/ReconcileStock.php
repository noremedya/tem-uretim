<?php

namespace App\Console\Commands;

use App\Services\StockAlertService;
use App\Support\Quantity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Tutarlılık kontrolü: her ürün ve depo için hareket toplamı ile stock_balances karşılaştırılır; ayrıca her mamul
 * kartının (tüm depolardaki) bakiyesi o modelin stoktaki motor sayısıyla karşılaştırılır. Fark varsa loglanır ve yöneticilere
 * bildirim gider. Otomatik düzeltme yapılmaz.
 */
class ReconcileStock extends Command
{
    protected $signature = 'stock:reconcile';

    protected $description = 'Stok bakiyelerini hareket toplamlarıyla karşılaştırır (düzeltme yapmaz)';

    public function handle(StockAlertService $alerts): int
    {
        $balancesOk = $this->checkBalances($alerts);
        $finishedOk = $this->checkFinishedGoods($alerts);

        if ($balancesOk && $finishedOk) {
            $this->info('Stok bakiyeleri tutarlı.');

            return self::SUCCESS;
        }

        return self::FAILURE;
    }

    /** Her depoda mamul bakiyesi = modelin o depodaki stoktaki (in_stock) motor sayısı. */
    private function checkFinishedGoods(StockAlertService $alerts): bool
    {
        $rows = DB::select(<<<'SQL'
            WITH keys AS (
                SELECT m.id AS motor_model_id, b.warehouse_id FROM motor_models m JOIN stock_balances b ON b.part_id = m.finished_part_id
                UNION SELECT motor_model_id, warehouse_id FROM motor_units WHERE status = 'in_stock'
            )
            SELECT p.code, p.name, w.name AS warehouse, COALESCE(b.quantity, 0) AS balance, COALESCE(u.in_stock, 0) AS in_stock
            FROM keys k
            JOIN motor_models m ON m.id = k.motor_model_id
            JOIN parts p ON p.id = m.finished_part_id
            JOIN warehouses w ON w.id = k.warehouse_id
            LEFT JOIN stock_balances b ON b.part_id = p.id AND b.warehouse_id = k.warehouse_id
            LEFT JOIN (
                SELECT motor_model_id, warehouse_id, count(*) AS in_stock FROM motor_units WHERE status = 'in_stock' GROUP BY motor_model_id, warehouse_id
            ) u ON u.motor_model_id = k.motor_model_id AND u.warehouse_id = k.warehouse_id
            WHERE COALESCE(b.quantity, 0) <> COALESCE(u.in_stock, 0)
            ORDER BY p.code, w.code
        SQL);

        if ($rows === []) {
            return true;
        }

        $mismatches = array_map(fn (object $row): array => [
            'code' => $row->code,
            'name' => $row->name,
            'warehouse' => $row->warehouse,
            'balance' => Quantity::parse($row->balance),
            'in_stock' => (int) $row->in_stock,
        ], $rows);

        Log::warning('stock:reconcile: mamul bakiyesi stoktaki motor sayısıyla uyuşmuyor.', ['mismatches' => $mismatches]);

        $this->error(sprintf('%d mamul kartında bakiye stoktaki motor sayısıyla uyuşmuyor:', count($mismatches)));
        $this->table(
            ['Kod', 'Ad', 'Depo', 'Bakiye', 'Stoktaki motor'],
            array_map(fn (array $m): array => [$m['code'], $m['name'], $m['warehouse'], Quantity::format($m['balance']), $m['in_stock']], $mismatches),
        );

        $alerts->finishedGoodMismatches($mismatches);

        return false;
    }

    private function checkBalances(StockAlertService $alerts): bool
    {
        $rows = DB::select(<<<'SQL'
            WITH keys AS (
                SELECT part_id, warehouse_id FROM stock_balances
                UNION SELECT part_id, warehouse_id FROM stock_movements
                -- Hiç bakiye satırı ve hareketi olmayan ürün (bakiye satırı eksik).
                UNION SELECT p.id, NULL FROM parts p WHERE NOT EXISTS (SELECT 1 FROM stock_balances b WHERE b.part_id = p.id)
            )
            SELECT p.id, p.code, p.name, w.name AS warehouse, b.quantity AS balance, COALESCE(m.total, 0) AS movements_total
            FROM keys k
            JOIN parts p ON p.id = k.part_id
            LEFT JOIN warehouses w ON w.id = k.warehouse_id
            LEFT JOIN stock_balances b ON b.part_id = k.part_id AND b.warehouse_id = k.warehouse_id
            LEFT JOIN (
                SELECT part_id, warehouse_id, SUM(quantity) AS total FROM stock_movements GROUP BY part_id, warehouse_id
            ) m ON m.part_id = k.part_id AND m.warehouse_id = k.warehouse_id
            WHERE b.quantity IS DISTINCT FROM COALESCE(m.total, 0)
            ORDER BY p.code, w.code
        SQL);

        if ($rows === []) {
            return true;
        }

        $mismatches = array_map(fn (object $row): array => [
            'part_id' => $row->id,
            'code' => $row->code,
            'name' => $row->name,
            'warehouse' => $row->warehouse ?? '—',
            'balance' => $row->balance === null ? null : Quantity::parse($row->balance),
            'movements_total' => Quantity::parse($row->movements_total),
        ], $rows);

        Log::warning('stock:reconcile: stok bakiyesi ile hareket toplamı arasında fark bulundu.', ['mismatches' => $mismatches]);

        $this->error(sprintf('%d üründe fark bulundu:', count($mismatches)));
        $this->table(
            ['Kod', 'Ad', 'Depo', 'Bakiye', 'Hareket toplamı'],
            array_map(fn (array $m): array => [
                $m['code'],
                $m['name'],
                $m['warehouse'],
                $m['balance'] === null ? 'yok' : Quantity::format($m['balance']),
                Quantity::format($m['movements_total']),
            ], $mismatches),
        );

        $alerts->reconcileMismatches($mismatches);

        return false;
    }
}
