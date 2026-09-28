<?php

namespace App\Console\Commands;

use App\Services\StockAlertService;
use App\Support\Quantity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Tutarlılık kontrolü: her parça için hareket toplamı ile stock_balances karşılaştırılır.
 * Fark varsa loglanır ve yöneticilere bildirim gider. Otomatik düzeltme yapılmaz.
 */
class ReconcileStock extends Command
{
    protected $signature = 'stock:reconcile';

    protected $description = 'Stok bakiyelerini hareket toplamlarıyla karşılaştırır (düzeltme yapmaz)';

    public function handle(StockAlertService $alerts): int
    {
        $rows = DB::select(<<<'SQL'
            SELECT p.id, p.code, p.name, b.quantity AS balance, COALESCE(m.total, 0) AS movements_total
            FROM parts p
            LEFT JOIN stock_balances b ON b.part_id = p.id
            LEFT JOIN (
                SELECT part_id, SUM(quantity) AS total FROM stock_movements GROUP BY part_id
            ) m ON m.part_id = p.id
            WHERE b.quantity IS DISTINCT FROM COALESCE(m.total, 0)
            ORDER BY p.code
        SQL);

        if ($rows === []) {
            $this->info('Stok bakiyeleri tutarlı.');

            return self::SUCCESS;
        }

        $mismatches = array_map(fn (object $row): array => [
            'part_id' => $row->id,
            'code' => $row->code,
            'name' => $row->name,
            'balance' => $row->balance === null ? null : Quantity::parse($row->balance),
            'movements_total' => Quantity::parse($row->movements_total),
        ], $rows);

        Log::warning('stock:reconcile: stok bakiyesi ile hareket toplamı arasında fark bulundu.', ['mismatches' => $mismatches]);

        $this->error(sprintf('%d parçada fark bulundu:', count($mismatches)));
        $this->table(
            ['Kod', 'Ad', 'Bakiye', 'Hareket toplamı'],
            array_map(fn (array $m): array => [
                $m['code'],
                $m['name'],
                $m['balance'] === null ? 'yok' : Quantity::format($m['balance']),
                Quantity::format($m['movements_total']),
            ], $mismatches),
        );

        $alerts->reconcileMismatches($mismatches);

        return self::FAILURE;
    }
}
