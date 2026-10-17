<?php

namespace App\Reports;

use App\Enums\Permission;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Üretim adedi: model × dönem. Kaynak üretilen motorlar (iptal edilenler hariç, üretim tarihine göre);
 * "yarı mamul dahil" seçilirse tamamlanan yarı mamul iş emirleri ayrı satırlarla eklenir.
 */
class ProductionCountReport extends Report
{
    public static function key(): string
    {
        return 'uretim_adedi';
    }

    public function title(): string
    {
        return 'Üretim adedi';
    }

    public function permission(): Permission
    {
        return Permission::ReportsProduction;
    }

    public function filters(): array
    {
        return ['grouping', 'motor_model_id', 'operator_id', 'batch_id', 'include_semi_finished'];
    }

    public function columns(): array
    {
        return [
            ReportColumn::text('period', 'Dönem'),
            ReportColumn::text('kind', 'Tür'),
            ReportColumn::text('code', 'Kod'),
            ReportColumn::text('name', 'Ad'),
            ReportColumn::decimal('quantity', 'Adet'),
            ReportColumn::text('unit', 'Birim'),
        ];
    }

    public function rows(ReportFilters $filters): array
    {
        $motors = DB::table('motor_units as mu')
            ->join('motor_models as mm', 'mm.id', '=', 'mu.motor_model_id')
            ->join('work_orders as wo', 'wo.id', '=', 'mu.work_order_id')
            ->where('mu.status', '!=', 'voided')
            ->whereBetween('mu.production_date', [$filters->from->toDateString(), $filters->to->toDateString()])
            ->when($filters->motorModelId, fn ($q, $id) => $q->where('mu.motor_model_id', $id))
            ->when($filters->batchId, fn ($q, $id) => $q->where('mu.production_batch_id', $id))
            ->when($filters->operatorId, fn ($q, $id) => $q->where('wo.assigned_user_id', $id))
            ->selectRaw('date_trunc(?, mu.production_date)::date AS bucket, mm.code, mm.name, count(*) AS quantity, ? AS kind, ? AS unit', [$filters->grouping, 'Motor', 'Adet'])
            ->groupByRaw('1, mm.code, mm.name')
            ->get();

        $semi = collect();

        if ($filters->includeSemiFinished && $filters->motorModelId === null) {
            $semi = DB::table('work_orders as wo')
                ->join('parts as p', 'p.id', '=', 'wo.product_part_id')
                ->join('units as u', 'u.id', '=', 'p.unit_id')
                ->where('wo.status', 'completed')
                ->where('p.type', 'semi_finished')
                ->where('wo.completed_at', '>=', $filters->from)
                ->where('wo.completed_at', '<', $filters->toExclusive())
                ->when($filters->batchId, fn ($q, $id) => $q->where('wo.production_batch_id', $id))
                ->when($filters->operatorId, fn ($q, $id) => $q->where('wo.assigned_user_id', $id))
                ->selectRaw('date_trunc(?, wo.completed_at)::date AS bucket, p.code, p.name, SUM(wo.quantity) AS quantity, ? AS kind, u.name AS unit', [$filters->grouping, 'Yarı mamul'])
                ->groupByRaw('1, p.code, p.name, u.name')
                ->get();
        }

        return $motors->concat($semi)
            ->sortBy(fn ($row) => [$row->bucket, $row->kind === 'Motor' ? 0 : 1, $row->code])
            ->map(fn ($row) => [
                'period' => ReportPeriod::bucketLabel(CarbonImmutable::parse($row->bucket), $filters->grouping),
                'kind' => $row->kind,
                'code' => $row->code,
                'name' => $row->name,
                'quantity' => (string) $row->quantity,
                'unit' => $row->unit,
            ])
            ->values()
            ->all();
    }

    public function summary(ReportFilters $filters, array $rows): array
    {
        $motors = collect($rows)->where('kind', 'Motor')->sum(fn ($r) => (float) $r['quantity']);

        return [
            'Toplam motor' => number_format($motors, 0, ',', '.'),
            'Model sayısı' => (string) collect($rows)->where('kind', 'Motor')->pluck('code')->unique()->count(),
        ];
    }
}
