<?php

namespace App\Reports;

use App\Enums\PartType;
use App\Enums\Permission;
use App\Enums\StockMovementType;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;

/**
 * Stok hareketleri ve dönem sonu bakiyeleri: parça başına açılış, hareket tiplerine göre giriş/çıkış ve dönem
 * sonu; muhasebe koduna göre sıralı. Dönem içinde hareketi veya açılış bakiyesi olan parçalar listelenir.
 * Çıkış sütunları pozitif gösterilir; sayım ve ters kayıt işaretlidir.
 */
class StockBalanceReport extends Report
{
    /** Sütun anahtarı => [hareket tipi, işaret çevrilsin mi] */
    private const MOVEMENT_COLUMNS = [
        'stock_in' => ['Giriş', false],
        'return' => ['İade', false],
        'production_output' => ['Üretimden giriş', false],
        'stock_out' => ['Çıkış', true],
        'production_consumption' => ['Üretim sarfı', true],
        'production_scrap' => ['Fire', true],
        'shipment' => ['Sevk', true],
        'count_adjustment' => ['Sayım farkı', false],
        'correction' => ['Ters kayıt', false],
    ];

    public static function key(): string
    {
        return 'stok_bakiyeleri';
    }

    public function title(): string
    {
        return 'Stok hareketleri ve dönem sonu bakiyeleri';
    }

    public function permission(): Permission
    {
        return Permission::ReportsStock;
    }

    public function filters(): array
    {
        return ['part_type', 'part_id'];
    }

    public function columns(): array
    {
        return [
            ReportColumn::text('accounting_code', 'Muhasebe kodu'),
            ReportColumn::text('code', 'Kod'),
            ReportColumn::text('name', 'Ad'),
            ReportColumn::text('unit', 'Birim'),
            ReportColumn::decimal('opening', 'Açılış'),
            ...collect(self::MOVEMENT_COLUMNS)->map(fn ($c, $key) => ReportColumn::decimal($key, $c[0]))->values()->all(),
            ReportColumn::decimal('closing', 'Dönem sonu'),
        ];
    }

    public function rows(ReportFilters $filters): array
    {
        $from = $filters->from;
        $to = $filters->toExclusive();

        $sums = collect(array_keys(self::MOVEMENT_COLUMNS))->map(fn (string $type) => sprintf(
            "COALESCE(SUM(m.quantity) FILTER (WHERE m.type = '%s' AND m.created_at >= :from_%s), 0) AS %s",
            $type, $type, $type,
        ))->implode(",\n");

        $bindings = ['to' => $to, 'opening_from' => $from, 'any_from' => $from];
        foreach (array_keys(self::MOVEMENT_COLUMNS) as $type) {
            $bindings["from_{$type}"] = $from;
        }

        $where = '';
        if ($filters->partType !== null) {
            $where .= ' AND p.type = :part_type';
            $bindings['part_type'] = $filters->partType;
        }
        if ($filters->partId !== null) {
            $where .= ' AND p.id = :part_id';
            $bindings['part_id'] = $filters->partId;
        }

        $rows = DB::select(<<<SQL
            SELECT p.code, p.name, p.type, u.name AS unit,
                COALESCE(SUM(m.quantity) FILTER (WHERE m.created_at < :opening_from), 0) AS opening,
                {$sums},
                COALESCE(SUM(m.quantity), 0) AS closing,
                count(m.id) FILTER (WHERE m.created_at >= :any_from) AS movement_count
            FROM parts p
            JOIN units u ON u.id = p.unit_id
            LEFT JOIN stock_movements m ON m.part_id = p.id AND m.created_at < :to
            WHERE TRUE {$where}
            GROUP BY p.id, p.code, p.name, p.type, u.name
            HAVING count(m.id) > 0
        SQL, $bindings);

        return collect($rows)
            ->filter(fn ($row) => (int) $row->movement_count > 0 || Quantity::compare((string) $row->opening, '0') !== 0)
            ->map(function ($row): array {
                $type = PartType::from($row->type);
                $result = [
                    'accounting_code' => $type->accountingCode(),
                    'code' => $row->code,
                    'name' => $row->name,
                    'unit' => $row->unit,
                    'opening' => Quantity::add('0', (string) $row->opening),
                ];

                foreach (self::MOVEMENT_COLUMNS as $key => [, $negate]) {
                    $value = Quantity::add('0', (string) $row->{$key});
                    $result[$key] = $negate ? Quantity::negate($value) : $value;
                }

                $result['closing'] = Quantity::add('0', (string) $row->closing);

                return $result;
            })
            ->sortBy(fn (array $r) => [$r['accounting_code'], $r['code']])
            ->values()
            ->all();
    }

    public function summary(ReportFilters $filters, array $rows): array
    {
        return [
            'Ürün sayısı' => (string) count($rows),
            'Hareket türleri' => implode(', ', array_map(fn ($t) => StockMovementType::from($t)->label(), array_keys(self::MOVEMENT_COLUMNS))),
        ];
    }
}
