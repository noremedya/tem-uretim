<?php

namespace App\Reports;

use App\Enums\Permission;
use App\Enums\StockMovementType;
use Illuminate\Support\Facades\DB;

/**
 * Stok hareket listesi (dönem içindeki tüm hareketler, tarih sırasıyla).
 */
class StockMovementListReport extends Report
{
    public static function key(): string
    {
        return 'stok_hareketleri';
    }

    public function title(): string
    {
        return 'Stok hareket listesi';
    }

    public function permission(): Permission
    {
        return Permission::ReportsStock;
    }

    public function filters(): array
    {
        return ['part_type', 'subtype_id', 'part_id', 'warehouse_id'];
    }

    public function columns(): array
    {
        return [
            ReportColumn::datetime('created_at', 'Tarih'),
            ReportColumn::text('code', 'Kod'),
            ReportColumn::text('name', 'Ad'),
            ReportColumn::text('warehouse', 'Depo'),
            ReportColumn::text('type', 'Hareket'),
            ReportColumn::decimal('quantity', 'Miktar'),
            ReportColumn::text('unit', 'Birim'),
            ReportColumn::decimal('balance_before', 'Önceki bakiye'),
            ReportColumn::decimal('balance_after', 'Sonraki bakiye'),
            ReportColumn::text('reference', 'Belge'),
            ReportColumn::text('user', 'Kullanıcı'),
            ReportColumn::text('description', 'Açıklama'),
        ];
    }

    public function rows(ReportFilters $filters): array
    {
        return DB::table('stock_movements as m')
            ->join('parts as p', 'p.id', '=', 'm.part_id')
            ->join('units as un', 'un.id', '=', 'p.unit_id')
            ->join('warehouses as w', 'w.id', '=', 'm.warehouse_id')
            ->join('users as u', 'u.id', '=', 'm.user_id')
            ->leftJoin('work_orders as wo', fn ($j) => $j->on('wo.id', '=', 'm.reference_id')->where('m.reference_type', 'work_order'))
            ->leftJoin('invoices as i', fn ($j) => $j->on('i.id', '=', 'm.reference_id')->where('m.reference_type', 'invoice'))
            ->leftJoin('motor_units as mu', fn ($j) => $j->on('mu.id', '=', 'm.reference_id')->where('m.reference_type', 'motor_unit'))
            ->where('m.created_at', '>=', $filters->from)
            ->where('m.created_at', '<', $filters->toExclusive())
            ->when($filters->partType, fn ($q, $type) => $q->where('p.type', $type))
            ->when($filters->subtypeId, fn ($q, $id) => $q->where('p.subtype_id', $id))
            ->when($filters->partId, fn ($q, $id) => $q->where('p.id', $id))
            ->when($filters->warehouseId, fn ($q, $id) => $q->where('m.warehouse_id', $id))
            ->orderBy('m.created_at')->orderBy('m.id')
            ->select([
                'm.created_at', 'p.code', 'p.name', 'w.name as warehouse', 'm.type', 'm.quantity', 'un.name as unit', 'm.balance_before', 'm.balance_after',
                'u.name as user', 'm.description',
                DB::raw('COALESCE(wo.work_order_number, i.invoice_number, i.counterparty_number, mu.serial_number) AS reference'),
            ])
            ->get()
            ->map(fn ($row) => [
                'created_at' => $row->created_at,
                'code' => $row->code,
                'name' => $row->name,
                'warehouse' => $row->warehouse,
                'type' => StockMovementType::from($row->type)->label(),
                'quantity' => (string) $row->quantity,
                'unit' => $row->unit,
                'balance_before' => (string) $row->balance_before,
                'balance_after' => (string) $row->balance_after,
                'reference' => $row->reference,
                'user' => $row->user,
                'description' => $row->description,
            ])
            ->all();
    }
}
