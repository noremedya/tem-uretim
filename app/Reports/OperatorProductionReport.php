<?php

namespace App\Reports;

use App\Enums\Permission;

/**
 * Operatör bazlı üretim: iş emri atanan operatöre yazılır; atanmamışlar "Atanmamış" satırındadır.
 */
class OperatorProductionReport extends Report
{
    public static function key(): string
    {
        return 'operator_uretimi';
    }

    public function title(): string
    {
        return 'Operatör bazlı üretim';
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
            ReportColumn::text('operator', 'Operatör'),
            ReportColumn::integer('work_orders', 'Tamamlanan iş emri'),
            ReportColumn::integer('motors', 'Üretilen motor'),
            ReportColumn::integer('semi_work_orders', 'Yarı mamul iş emri'),
            ReportColumn::hours('avg_hours', 'Ortalama süre (saat)'),
            ReportColumn::percent('on_time_rate', 'Zamanında oranı'),
        ];
    }

    public function rows(ReportFilters $filters): array
    {
        return WorkOrderPerformanceReport::completedQuery($filters)
            ->selectRaw(<<<'SQL'
                u.name AS operator,
                count(*) AS work_orders,
                COALESCE(SUM(wo.quantity) FILTER (WHERE wo.motor_model_id IS NOT NULL), 0) AS motors,
                count(*) FILTER (WHERE wo.motor_model_id IS NULL) AS semi_work_orders,
                round(AVG(EXTRACT(EPOCH FROM (wo.completed_at - wo.started_at)) / 3600)::numeric, 1) AS avg_hours,
                round(100.0 * count(*) FILTER (WHERE wo.completed_at::date <= wo.planned_end) / count(*), 1) AS on_time_rate
            SQL)
            ->groupBy('u.name')
            ->orderByRaw('u.name NULLS LAST')
            ->get()
            ->map(fn ($row) => [
                'operator' => $row->operator ?? 'Atanmamış',
                'work_orders' => (int) $row->work_orders,
                'motors' => (int) $row->motors,
                'semi_work_orders' => (int) $row->semi_work_orders,
                'avg_hours' => (string) $row->avg_hours,
                'on_time_rate' => (string) $row->on_time_rate,
            ])
            ->all();
    }

    public function summary(ReportFilters $filters, array $rows): array
    {
        return [
            'Operatör sayısı' => (string) collect($rows)->where('operator', '!=', 'Atanmamış')->count(),
            'Tamamlanan iş emri' => (string) collect($rows)->sum('work_orders'),
            'Üretilen motor' => (string) collect($rows)->sum('motors'),
        ];
    }
}
