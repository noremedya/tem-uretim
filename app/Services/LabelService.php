<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\MotorUnit;
use App\Models\User;
use App\Models\WorkOrder;
use App\Support\QrCode;
use App\Support\Quantity;
use Illuminate\Support\Collection;

/**
 * Etiket içerikleri (CLAUDE.md bölüm 7 "Etiket yazdırma"). QR yalnızca kodu taşır (seri no / iş emri no);
 * etiket bir takip etiketidir, motorun yasal plakası değildir. Yetki: kaydı görebilen etiketini de alır.
 */
class LabelService
{
    /**
     * @param  Collection<int, MotorUnit>  $units
     * @return list<array{code: string, qr: string, title: string, lines: list<string>}>
     */
    public function motorLabels(Collection $units, User $user): array
    {
        $this->ensureCount($units->count());

        return $units
            ->each(fn (MotorUnit $unit) => $user->can('view', $unit) ?: throw new BusinessRuleException("{$unit->serial_number} etiketini yazdırma yetkiniz yok."))
            ->map(function (MotorUnit $unit): array {
                $model = $unit->motorModel;
                $ratings = array_filter([
                    $model->power_kw !== null ? Quantity::format((string) $model->power_kw).' kW' : null,
                    $model->speed_rpm ? "{$model->speed_rpm} d/dk" : null,
                    $model->voltage,
                    $model->frequency_hz ? "{$model->frequency_hz} Hz" : null,
                ]);

                return [
                    'code' => $unit->serial_number,
                    'qr' => QrCode::svg($unit->serial_number),
                    'title' => "{$model->code} — {$model->name}",
                    'lines' => array_values(array_filter([
                        implode(' · ', $ratings) ?: null,
                        implode(' · ', array_filter([$model->protection_class, $model->efficiency_class])) ?: null,
                        'Üretim: '.$unit->production_date->format('m.Y').' · '.($unit->workOrder !== null ? "İE: {$unit->workOrder->work_order_number}" : 'Açılış kaydı')
                            .($unit->productionBatch ? ' · Parti: '.$unit->productionBatch->batch_number : ''),
                    ])),
                ];
            })
            ->values()
            ->all();
    }

    /** @return list<array{code: string, qr: string, title: string, lines: list<string>}> */
    public function workOrderLabels(WorkOrder $workOrder, User $user, int $copies = 1): array
    {
        if (! $user->can('view', $workOrder)) {
            throw new BusinessRuleException('Bu iş emrinin etiketini yazdırma yetkiniz yok.');
        }

        $this->ensureCount($copies);

        $workOrder->loadMissing(['product.unit', 'productionBatch', 'orderItem.order.customer', 'assignedUser']);
        $order = $workOrder->orderItem?->order;

        $label = [
            'code' => $workOrder->work_order_number,
            'qr' => QrCode::svg($workOrder->work_order_number),
            'title' => "{$workOrder->product->code} — {$workOrder->product->name}",
            'lines' => array_values(array_filter([
                'Adet: '.Quantity::format((string) $workOrder->quantity, $workOrder->product->unit->name)
                    .' · Bitiş: '.$workOrder->planned_end->format('d.m.Y'),
                // Müşteri adı yalnızca cari görebilene (operatör ve depo görmez).
                $order ? "Sipariş: {$order->order_number}".($user->canSeeCustomers() ? " · {$order->customer->name}" : '') : null,
                implode(' · ', array_filter([
                    $workOrder->productionBatch ? 'Parti: '.$workOrder->productionBatch->batch_number : null,
                    'Operatör: '.($workOrder->assignedUser?->name ?? 'Atanmadı'),
                ])),
            ])),
        ];

        return array_fill(0, $copies, $label);
    }

    /** @return Collection<int, MotorUnit> */
    public function motorsFor(array $ids, ?int $workOrderId): Collection
    {
        return MotorUnit::query()
            ->with(['motorModel', 'workOrder', 'productionBatch'])
            ->when($workOrderId, fn ($q) => $q->where('work_order_id', $workOrderId), fn ($q) => $q->whereIn('id', $ids))
            ->orderBy('serial_number')
            ->get();
    }

    private function ensureCount(int $count): void
    {
        $max = (int) config('labels.max_labels');

        if ($count < 1) {
            throw new BusinessRuleException('Yazdırılacak etiket seçilmedi.');
        }

        if ($count > $max) {
            throw new BusinessRuleException("Tek seferde en fazla {$max} etiket yazdırılabilir ({$count} seçildi).");
        }
    }
}
