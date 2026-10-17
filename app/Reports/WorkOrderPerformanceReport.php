<?php

namespace App\Reports;

use App\Enums\Permission;
use Illuminate\Support\Facades\DB;

/**
 * Tamamlanan iş emirleri: süre (takvim saati, başlama → bitiş) ve zamanında tamamlanma (tamamlanma tarihi ≤
 * planlanan bitiş). Tarih aralığı tamamlanma zamanına göredir.
 */
class WorkOrderPerformanceReport extends Report
{
    public static function key(): string
    {
        return 'is_emri_performansi';
    }

    public function title(): string
    {
        return 'İş emri performansı';
    }

    public function permission(): Permission
    {
        return Permission::ReportsProduction;
    }

    public function filters(): array
    {
        return ['motor_model_id', 'operator_id', 'batch_id'];
    }

    public function columns(): array
    {
        return [
            ReportColumn::text('number', 'İş emri no'),
            ReportColumn::text('product', 'Üretilen'),
            ReportColumn::decimal('quantity', 'Adet'),
            ReportColumn::text('operator', 'Operatör'),
            ReportColumn::datetime('started_at', 'Başlama'),
            ReportColumn::datetime('completed_at', 'Bitiş'),
            ReportColumn::hours('hours', 'Süre (saat)'),
            ReportColumn::date('planned_end', 'Planlanan bitiş'),
            ReportColumn::boolean('on_time', 'Zamanında'),
        ];
    }

    public static function completedQuery(ReportFilters $filters)
    {
        return DB::table('work_orders as wo')
            ->join('parts as p', 'p.id', '=', 'wo.product_part_id')
            ->leftJoin('users as u', 'u.id', '=', 'wo.assigned_user_id')
            ->where('wo.status', 'completed')
            ->where('wo.completed_at', '>=', $filters->from)
            ->where('wo.completed_at', '<', $filters->toExclusive())
            ->when($filters->motorModelId, fn ($q, $id) => $q->where('wo.motor_model_id', $id))
            ->when($filters->operatorId, fn ($q, $id) => $q->where('wo.assigned_user_id', $id))
            ->when($filters->batchId, fn ($q, $id) => $q->where('wo.production_batch_id', $id));
    }

    public function rows(ReportFilters $filters): array
    {
        return static::completedQuery($filters)
            ->selectRaw(<<<'SQL'
                wo.work_order_number AS number, p.code || ' ' || p.name AS product, wo.quantity, u.name AS operator,
                wo.started_at, wo.completed_at, wo.planned_end,
                round((EXTRACT(EPOCH FROM (wo.completed_at - wo.started_at)) / 3600)::numeric, 1) AS hours,
                (wo.completed_at::date <= wo.planned_end) AS on_time
            SQL)
            ->orderBy('wo.completed_at')
            ->get()
            ->map(fn ($row) => [
                'number' => $row->number,
                'product' => $row->product,
                'quantity' => (string) $row->quantity,
                'operator' => $row->operator ?? 'Atanmamış',
                'started_at' => $row->started_at,
                'completed_at' => $row->completed_at,
                'hours' => (string) $row->hours,
                'planned_end' => $row->planned_end,
                'on_time' => (bool) $row->on_time,
            ])
            ->all();
    }

    public function summary(ReportFilters $filters, array $rows): array
    {
        $count = count($rows);
        $avg = $count > 0 ? collect($rows)->avg(fn ($r) => (float) $r['hours']) : 0;
        $onTime = collect($rows)->where('on_time', true)->count();

        return [
            'Tamamlanan iş emri' => (string) $count,
            'Ortalama üretim süresi' => number_format($avg, 1, ',', '.').' saat',
            'Zamanında tamamlanma oranı' => $count > 0 ? '%'.number_format($onTime / $count * 100, 1, ',', '.') : '—',
        ];
    }
}
