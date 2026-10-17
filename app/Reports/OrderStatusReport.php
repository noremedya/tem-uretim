<?php

namespace App\Reports;

use App\Enums\OrderStatus;
use App\Enums\Permission;
use Illuminate\Support\Facades\DB;

/**
 * Sipariş durumu: kalem başına sipariş, üretilen (iptal edilmemiş motor), sevk edilen ve kalan adet.
 * Tarih aralığı sipariş tarihine göredir. Gecikme: termin geçmiş ve kalan adet var (açık/kısmi siparişte).
 */
class OrderStatusReport extends Report
{
    public static function key(): string
    {
        return 'siparis_durumu';
    }

    public function title(): string
    {
        return 'Sipariş durumu';
    }

    public function permission(): Permission
    {
        return Permission::ReportsProduction;
    }

    public function filters(): array
    {
        return ['customer_id', 'order_status', 'motor_model_id'];
    }

    public function columns(): array
    {
        return [
            ReportColumn::text('order_number', 'Sipariş no'),
            ReportColumn::text('customer', 'Müşteri'),
            ReportColumn::date('order_date', 'Sipariş tarihi'),
            ReportColumn::date('due_date', 'Termin'),
            ReportColumn::text('status', 'Durum'),
            ReportColumn::text('model', 'Model'),
            ReportColumn::integer('ordered', 'Sipariş adedi'),
            ReportColumn::integer('produced', 'Üretilen'),
            ReportColumn::integer('shipped', 'Sevk edilen'),
            ReportColumn::integer('remaining', 'Kalan'),
            ReportColumn::boolean('overdue', 'Gecikmede'),
        ];
    }

    public function rows(ReportFilters $filters): array
    {
        return DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->join('customers as c', 'c.id', '=', 'o.customer_id')
            ->join('motor_models as mm', 'mm.id', '=', 'oi.motor_model_id')
            ->leftJoin('motor_units as mu', fn ($j) => $j->on('mu.order_id', '=', 'o.id')->on('mu.motor_model_id', '=', 'oi.motor_model_id')->where('mu.status', '!=', 'voided'))
            ->whereBetween('o.order_date', [$filters->from->toDateString(), $filters->to->toDateString()])
            ->when($filters->customerId, fn ($q, $id) => $q->where('o.customer_id', $id))
            ->when($filters->orderStatus, fn ($q, $status) => $q->where('o.status', $status))
            ->when($filters->motorModelId, fn ($q, $id) => $q->where('oi.motor_model_id', $id))
            ->groupBy('oi.id', 'o.order_number', 'c.name', 'o.order_date', 'o.due_date', 'o.status', 'mm.code', 'mm.name', 'oi.quantity')
            ->orderBy('o.due_date')->orderBy('o.order_number')
            ->selectRaw(<<<'SQL'
                o.order_number, c.name AS customer, o.order_date, o.due_date, o.status, mm.code || ' ' || mm.name AS model,
                oi.quantity AS ordered, count(mu.id) AS produced, count(mu.id) FILTER (WHERE mu.status = 'shipped') AS shipped
            SQL)
            ->get()
            ->map(function ($row): array {
                $status = OrderStatus::from($row->status);
                $remaining = max(0, (int) $row->ordered - (int) $row->produced);

                return [
                    'order_number' => $row->order_number,
                    'customer' => $row->customer,
                    'order_date' => $row->order_date,
                    'due_date' => $row->due_date,
                    'status' => $status->label(),
                    'model' => $row->model,
                    'ordered' => (int) $row->ordered,
                    'produced' => (int) $row->produced,
                    'shipped' => (int) $row->shipped,
                    'remaining' => $remaining,
                    'overdue' => $remaining > 0 && $status->isEditable() && $row->due_date < today()->toDateString(),
                ];
            })
            ->all();
    }

    public function summary(ReportFilters $filters, array $rows): array
    {
        $rows = collect($rows);

        return [
            'Kalem' => (string) $rows->count(),
            'Sipariş adedi' => (string) $rows->sum('ordered'),
            'Üretilen' => (string) $rows->sum('produced'),
            'Kalan' => (string) $rows->sum('remaining'),
            'Gecikmedeki kalem' => (string) $rows->where('overdue', true)->count(),
        ];
    }
}
