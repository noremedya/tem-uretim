<?php

namespace App\Services;

use App\Enums\MotorUnitStatus;
use App\Enums\OrderStatus;
use App\Enums\Permission;
use App\Enums\StockMovementType;
use App\Enums\WorkOrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\InsufficientStockException;
use App\Models\MotorUnit;
use App\Models\Part;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\WorkOrder;
use App\Support\Quantity;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Üretim akışları (CLAUDE.md bölüm 5): iş emri tamamlama ve motor sevki. Geri alma Aşama 4d'dedir.
 */
class ProductionService
{
    public function __construct(
        private readonly StockService $stock,
        private readonly NumberSequenceService $numbers,
        private readonly OrderService $orders,
        private readonly WorkOrderService $workOrders,
    ) {}

    /**
     * İş emrini tamamlar (tek transaction): net sarf ve fire düşülür, ürün stoğa girer, mamulde adet kadar
     * seri numaralı motor kaydı oluşur, siparişe bağlıysa sipariş durumu yeniden hesaplanır.
     * Herhangi bir malzeme yetersizse hiçbir şey değişmez.
     *
     * @param  array<int, string|int|float|null>  $actualScraps  part_id => gerçekleşen fire (verilmezse standart fire)
     */
    public function complete(WorkOrder $workOrder, User $actor, array $actualScraps = []): WorkOrder
    {
        return DB::transaction(function () use ($workOrder, $actor, $actualScraps): WorkOrder {
            // Satır kilidi: aynı iş emri iki kez tamamlanamaz; durum kilit altında okunur.
            $locked = WorkOrder::query()
                ->with(['materials.part.unit', 'product.unit', 'motorModel', 'orderItem.order'])
                ->lockForUpdate()
                ->findOrFail($workOrder->getKey());

            $locked->status->ensureCanTransitionTo(WorkOrderStatus::Completed);

            if (! $this->workOrders->canTransition($actor, $locked, WorkOrderStatus::Completed)) {
                throw new BusinessRuleException('Bu iş emrinde bu işlemi yapma yetkiniz yok.');
            }

            $lines = [];
            foreach ($locked->materials as $material) {
                $scrap = $this->scrap($actualScraps[$material->part_id] ?? null, (string) $material->planned_scrap, $material->part);

                $material->actual_scrap = $scrap;
                $material->save();

                $lines[] = ['part_id' => $material->part_id, 'consumption' => (string) $material->required_quantity, 'scrap' => $scrap];
            }

            $this->stock->recordProduction($lines, $locked->product, (string) $locked->quantity, $locked, $actor, $locked->work_order_number);

            if ($locked->motor_model_id !== null) {
                $this->createMotorUnits($locked);
            }

            $locked->status = WorkOrderStatus::Completed;
            $locked->completed_at = now();
            $locked->save();
            $this->workOrders->recordHistory($locked, WorkOrderStatus::InProgress, WorkOrderStatus::Completed, $actor);

            if ($locked->orderItem !== null) {
                $this->orders->recalculateStatus($locked->orderItem->order);
            }

            $workOrder->setRawAttributes($locked->getAttributes(), sync: true);

            return $workOrder;
        });
    }

    /**
     * Tamamlanmış iş emrini geri alır (yalnızca yönetici, açıklama zorunlu, tek transaction): motorların hepsi
     * stokta olmalı; sarf, fire ve üretimden giriş ters kaydedilir; motorlar iptal edilir (silinmez, seri no
     * tekrar kullanılmaz); iş emri üretimde durumuna döner; sipariş durumu yeniden hesaplanır.
     */
    public function reverse(WorkOrder $workOrder, User $actor, ?string $reason): WorkOrder
    {
        $reason = trim((string) $reason);

        if ($reason === '') {
            throw new BusinessRuleException('Geri alma açıklaması zorunludur.');
        }

        return DB::transaction(function () use ($workOrder, $actor, $reason): WorkOrder {
            $locked = WorkOrder::query()->with('orderItem.order')->lockForUpdate()->findOrFail($workOrder->getKey());

            $locked->status->ensureCanTransitionTo(WorkOrderStatus::InProgress);

            if ($locked->status !== WorkOrderStatus::Completed || ! $this->workOrders->canTransition($actor, $locked, WorkOrderStatus::InProgress)) {
                throw new BusinessRuleException('Tamamlanmış iş emrini yalnızca yönetici geri alabilir.');
            }

            // Motorlar kilitlenir: aynı anda sevk edilemezler.
            $units = $locked->motorUnits()->where('status', '!=', MotorUnitStatus::Voided->value)->orderBy('id')->lockForUpdate()->get();
            $notInStock = $units->filter(fn (MotorUnit $unit) => $unit->status !== MotorUnitStatus::InStock);

            if ($notInStock->isNotEmpty()) {
                throw new BusinessRuleException('Sevk edilmiş motor var, üretim geri alınamaz: '.$notInStock->pluck('serial_number')->implode(', '));
            }

            // Son tamamlamanın henüz ters kaydedilmemiş üretim hareketleri.
            $movements = StockMovement::query()
                ->where('reference_type', $locked->getMorphClass())
                ->where('reference_id', $locked->getKey())
                ->whereIn('type', [StockMovementType::ProductionConsumption->value, StockMovementType::ProductionScrap->value, StockMovementType::ProductionOutput->value])
                ->whereDoesntHave('correction')
                ->orderBy('id')
                ->get();

            try {
                $this->stock->reverseProduction($movements, $locked, $actor, "Geri alma ({$locked->work_order_number}): {$reason}");
            } catch (InsufficientStockException $exception) {
                throw new BusinessRuleException("Üretilen ürün bu arada kullanılmış; geri alma stoğu eksiye düşürür.\n".implode("\n", array_slice(explode("\n", $exception->getMessage()), 1)));
            }

            foreach ($units as $unit) {
                $unit->status = MotorUnitStatus::Voided;
                $unit->save();
            }

            $locked->materials()->update(['actual_scrap' => null]);

            $locked->status = WorkOrderStatus::InProgress;
            $locked->completed_at = null;
            $locked->save();
            $this->workOrders->recordHistory($locked, WorkOrderStatus::Completed, WorkOrderStatus::InProgress, $actor, $reason);

            if ($locked->orderItem !== null) {
                $this->orders->recalculateStatus($locked->orderItem->order);
            }

            $workOrder->setRawAttributes($locked->getAttributes(), sync: true);

            return $workOrder;
        });
    }

    /**
     * Stoktaki motorları sevk eder: motor "sevk edildi" olur, mamul kartından sevk hareketiyle düşülür.
     *
     * @param  iterable<MotorUnit|int>  $units
     * @return Collection<int, MotorUnit>
     */
    public function ship(iterable $units, User $actor, ?string $note = null): Collection
    {
        if (! $actor->is_active || ! $actor->can(Permission::MotorUnitsShip)) {
            throw new BusinessRuleException('Motor sevk etme yetkiniz yok.');
        }

        $ids = collect($units)->map(fn (MotorUnit|int $unit) => $unit instanceof MotorUnit ? $unit->getKey() : $unit)->unique()->sort()->values();

        if ($ids->isEmpty()) {
            throw new BusinessRuleException('Sevk edilecek motor seçilmedi.');
        }

        $note = filled($note) ? trim($note) : null;

        return DB::transaction(function () use ($ids, $actor, $note): Collection {
            $locked = MotorUnit::query()->with('motorModel.finishedPart')->whereKey($ids)->orderBy('id')->lockForUpdate()->get();

            $notInStock = $locked->filter(fn (MotorUnit $unit) => $unit->status !== MotorUnitStatus::InStock);

            if ($notInStock->isNotEmpty() || $locked->count() !== $ids->count()) {
                throw new BusinessRuleException('Yalnızca stoktaki motorlar sevk edilebilir: '.$notInStock->pluck('serial_number')->implode(', '));
            }

            foreach ($locked as $unit) {
                $this->stock->recordShipment($unit->motorModel->finishedPart, 1, $unit, $actor, $note ?? "Sevk: {$unit->serial_number}");

                $unit->forceFill([
                    'status' => MotorUnitStatus::Shipped,
                    'shipped_at' => now(),
                    'shipped_by_user_id' => $actor->getKey(),
                    'notes' => $note ?? $unit->notes,
                ])->save();
            }

            return $locked;
        });
    }

    private function createMotorUnits(WorkOrder $workOrder): void
    {
        $order = $workOrder->orderItem?->order;
        // İptal edilmiş siparişe motor bağlanmaz.
        $orderId = $order !== null && $order->status !== OrderStatus::Cancelled ? $order->getKey() : null;

        for ($i = 0; $i < (int) $workOrder->quantity; $i++) {
            $unit = new MotorUnit;
            $unit->forceFill([
                'serial_number' => $this->numbers->next('serial_number', now(), ['MODEL_KODU' => $workOrder->motorModel->code]),
                'motor_model_id' => $workOrder->motor_model_id,
                'work_order_id' => $workOrder->getKey(),
                'production_batch_id' => $workOrder->production_batch_id,
                'order_id' => $orderId,
                'production_date' => today(),
                'status' => MotorUnitStatus::InStock,
            ])->save();
        }
    }

    private function scrap(mixed $value, string $planned, Part $part): string
    {
        if ($value === null || $value === '') {
            return $planned;
        }

        $scrap = Quantity::parse($value);

        if (Quantity::compare($scrap, '0') < 0) {
            throw new BusinessRuleException("{$part->code} {$part->name} için fire negatif olamaz.");
        }

        if (! $part->unit->allows_decimal && ! Quantity::isWhole($scrap)) {
            throw new BusinessRuleException("{$part->code} {$part->name} için fire tam sayı olmalıdır (birim: {$part->unit->name}).");
        }

        return $scrap;
    }
}
