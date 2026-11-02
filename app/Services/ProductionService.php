<?php

namespace App\Services;

use App\Enums\MotorUnitStatus;
use App\Enums\OrderStatus;
use App\Enums\Permission;
use App\Enums\StockMovementType;
use App\Enums\WorkOrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\InsufficientStockException;
use App\Models\MotorModel;
use App\Models\MotorUnit;
use App\Models\MotorUnitReturn;
use App\Models\Part;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WorkOrder;
use App\Support\Quantity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Üretim akışları (CLAUDE.md bölüm 5): iş emri tamamlama ve motor sevki. Geri alma Aşama 4d'dedir.
 */
class ProductionService
{
    public const MAX_OPENING_MOTORS = 500;

    public function __construct(
        private readonly StockService $stock,
        private readonly NumberSequenceService $numbers,
        private readonly OrderService $orders,
        private readonly WorkOrderService $workOrders,
        private readonly WarehouseService $warehouses,
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
                ->with(['materials.part.unit', 'outputs', 'product.unit', 'motorModel', 'orderItem.order', 'targetWarehouse'])
                ->lockForUpdate()
                ->findOrFail($workOrder->getKey());

            $locked->status->ensureCanTransitionTo(WorkOrderStatus::Completed);

            if (! $this->workOrders->canTransition($actor, $locked, WorkOrderStatus::Completed)) {
                throw new BusinessRuleException('Bu iş emrinde bu işlemi yapma yetkiniz yok.');
            }

            $lines = [];
            // Eş ürünler çıktı deposuna; fire kartı (hurda) seçilmiş satırların gerçekleşen firesi o karta (kendi deposuna).
            $extraOutputs = $locked->outputs->map(fn ($output) => ['part_id' => $output->part_id, 'quantity' => (string) $output->quantity])->all();
            foreach ($locked->materials as $material) {
                $scrap = $this->scrap($actualScraps[$material->part_id] ?? null, (string) $material->planned_scrap, $material->part);

                $material->actual_scrap = $scrap;
                $material->save();

                $lines[] = ['part_id' => $material->part_id, 'warehouse_id' => $material->warehouse_id, 'consumption' => (string) $material->required_quantity, 'scrap' => $scrap];

                if ($material->scrap_part_id !== null && Quantity::compare($scrap, '0') > 0) {
                    $extraOutputs[] = [
                        'part_id' => $material->scrap_part_id,
                        'quantity' => $scrap,
                        'warehouse_id' => $this->warehouses->defaultFor(Part::query()->findOrFail($material->scrap_part_id))->getKey(),
                    ];
                }
            }

            $this->stock->recordProduction($lines, $locked->product, (string) $locked->quantity, $locked, $actor, $locked->work_order_number, $locked->targetWarehouse, $extraOutputs);

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

            // İade gelen motor bir kez müşteriye gitmiştir; sevk ve iade kayıtları üretimi geri almaya izin vermez.
            $returned = $locked->motorUnits()->whereHas('returns')->pluck('serial_number');

            if ($returned->isNotEmpty()) {
                throw new BusinessRuleException('İade gelmiş motor var, üretim geri alınamaz: '.$returned->implode(', '));
            }

            // Üretimden giriş çıktı deposunda ters kaydedilir: motorlar orada olmalı.
            $moved = $units->filter(fn (MotorUnit $unit) => $unit->warehouse_id !== $locked->target_warehouse_id);

            if ($moved->isNotEmpty()) {
                throw new BusinessRuleException('Bazı motorlar başka depoya aktarılmış; önce iş emrinin çıktı deposuna geri aktarın: '.$moved->pluck('serial_number')->implode(', '));
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
            $locked = MotorUnit::query()->with(['motorModel.finishedPart', 'warehouse'])->whereKey($ids)->orderBy('id')->lockForUpdate()->get();

            $notInStock = $locked->filter(fn (MotorUnit $unit) => $unit->status !== MotorUnitStatus::InStock);

            if ($notInStock->isNotEmpty() || $locked->count() !== $ids->count()) {
                throw new BusinessRuleException('Yalnızca stoktaki motorlar sevk edilebilir: '.$notInStock->pluck('serial_number')->implode(', '));
            }

            foreach ($locked as $unit) {
                // Motor bulunduğu depodan sevk edilir.
                $this->stock->recordShipment($unit->motorModel->finishedPart, 1, $unit, $actor, $note ?? "Sevk: {$unit->serial_number}", $unit->warehouse);

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

    /**
     * Stoktaki motorları başka depoya aktarır (yönetici ve depo; stok hareketi yetkisi): motor başına mamul kartında
     * bir transfer (kaynakta −1, hedefte +1, referans motor). Seçilenlerden biri stokta değilse veya zaten hedef
     * depodaysa hiçbiri aktarılmaz.
     *
     * @param  iterable<MotorUnit|int>  $units
     * @return Collection<int, MotorUnit>
     */
    public function transferMotors(iterable $units, Warehouse $to, User $actor, ?string $note = null): Collection
    {
        if (! $actor->is_active || ! $actor->can(Permission::StockMove)) {
            throw new BusinessRuleException('Motor aktarma yetkiniz yok.');
        }

        $ids = collect($units)->map(fn (MotorUnit|int $unit) => $unit instanceof MotorUnit ? $unit->getKey() : $unit)->unique()->sort()->values();

        if ($ids->isEmpty()) {
            throw new BusinessRuleException('Aktarılacak motor seçilmedi.');
        }

        $note = filled($note) ? trim($note) : null;

        return DB::transaction(function () use ($ids, $to, $actor, $note): Collection {
            $locked = MotorUnit::query()->with(['motorModel.finishedPart', 'warehouse'])->whereKey($ids)->orderBy('id')->lockForUpdate()->get();

            $invalid = $locked->filter(fn (MotorUnit $unit) => $unit->status !== MotorUnitStatus::InStock || $unit->warehouse_id === $to->getKey());

            if ($invalid->isNotEmpty() || $locked->count() !== $ids->count()) {
                throw new BusinessRuleException("Yalnızca stokta olan ve {$to->name} dışındaki motorlar aktarılabilir: ".$invalid->pluck('serial_number')->implode(', '));
            }

            foreach ($locked as $unit) {
                $this->stock->transferMotor($unit->motorModel->finishedPart, $unit, $unit->warehouse, $to, $actor, $note ?? "Motor aktarımı: {$unit->serial_number}");

                $unit->warehouse_id = $to->getKey();
                $unit->save();
            }

            return $locked;
        });
    }

    /**
     * Sistem öncesinden stokta olan motorlar (yalnızca yönetici): verilen seri numaraları olduğu gibi, $generate kadar
     * motor sistemin ürettiği numarayla açılır; her motor için mamul kartına bir giriş yazılır. Biri reddedilirse
     * hiçbiri açılmaz.
     *
     * @param  list<string>  $serials
     * @return Collection<int, MotorUnit>
     */
    public function registerOpeningMotors(MotorModel $model, Warehouse $warehouse, array $serials, int $generate, mixed $productionDate, User $actor, ?string $note = null): Collection
    {
        if (! $actor->is_active || ! $actor->can(Permission::MotorUnitsOpening)) {
            throw new BusinessRuleException('Açılış motoru girme yetkiniz yok.');
        }

        $serials = array_values(array_filter(array_map(fn (string $serial): string => trim($serial), $serials), fn (string $serial): bool => $serial !== ''));
        $lowered = array_map(fn (string $serial): string => mb_strtolower($serial), $serials);

        if ($generate < 0 || count($serials) + $generate === 0) {
            throw new BusinessRuleException('Seri numarası veya üretilecek adet girin.');
        }

        if (count($serials) + $generate > self::MAX_OPENING_MOTORS) {
            throw new BusinessRuleException('Tek seferde en fazla '.self::MAX_OPENING_MOTORS.' motor girilebilir.');
        }

        if (count(array_unique($lowered)) !== count($lowered)) {
            throw new BusinessRuleException('Listede aynı seri numarası birden fazla var.');
        }

        foreach ($serials as $serial) {
            if (mb_strlen($serial) > 100) {
                throw new BusinessRuleException("Seri numarası çok uzun: {$serial}");
            }
        }

        $date = filled($productionDate) ? CarbonImmutable::parse($productionDate)->startOfDay() : CarbonImmutable::today();

        if ($date->gt(today())) {
            throw new BusinessRuleException('Üretim tarihi ileri bir tarih olamaz.');
        }

        $note = filled($note) ? trim($note) : null;

        return DB::transaction(function () use ($model, $warehouse, $serials, $lowered, $generate, $date, $actor, $note): Collection {
            $model = MotorModel::query()->with('finishedPart')->lockForUpdate()->findOrFail($model->getKey());

            if (! $model->is_active) {
                throw new BusinessRuleException("{$model->code} pasif; açılış motoru girilemez.");
            }

            $taken = MotorUnit::query()->whereIn(DB::raw('lower(serial_number)'), $lowered)->pluck('serial_number');

            if ($taken->isNotEmpty()) {
                throw new BusinessRuleException('Bu seri numaraları zaten kayıtlı: '.$taken->implode(', '));
            }

            for ($i = 0; $i < $generate; $i++) {
                $serials[] = $this->numbers->next('serial_number', now(), ['MODEL_KODU' => $model->code]);
            }

            return collect($serials)->map(function (string $serial) use ($model, $warehouse, $date, $actor, $note): MotorUnit {
                $unit = new MotorUnit;
                $unit->forceFill([
                    'serial_number' => $serial,
                    'motor_model_id' => $model->getKey(),
                    'warehouse_id' => $warehouse->getKey(),
                    'production_date' => $date,
                    'status' => MotorUnitStatus::InStock,
                    'notes' => $note,
                ])->save();

                $this->stock->recordMotorOpening($model->finishedPart, $unit, $actor, "Açılış motoru: {$serial}", $warehouse);

                return $unit;
            });
        });
    }

    /**
     * Yanlış girilen açılış motorunu iptal eder (yalnızca yönetici, açıklama zorunlu): motor hiç sevk edilmemiş ve
     * giriş deposunda olmalı. Giriş ters kaydedilir, motor iptal edilir; seri no tekrar kullanılmaz.
     */
    public function cancelOpeningMotor(MotorUnit $unit, User $actor, ?string $reason): MotorUnit
    {
        $reason = trim((string) $reason);

        if (! $actor->is_active || ! $actor->can(Permission::MotorUnitsOpening)) {
            throw new BusinessRuleException('Açılış motorunu iptal etme yetkiniz yok.');
        }

        if ($reason === '') {
            throw new BusinessRuleException('İptal açıklaması zorunludur.');
        }

        return DB::transaction(function () use ($unit, $actor, $reason): MotorUnit {
            $locked = MotorUnit::query()->lockForUpdate()->findOrFail($unit->getKey());
            $opening = StockMovement::query()
                ->where('reference_type', $locked->getMorphClass())
                ->where('reference_id', $locked->getKey())
                ->where('type', StockMovementType::StockIn->value)
                ->first();

            if (! $locked->isOpening() || $locked->status !== MotorUnitStatus::InStock || $opening === null) {
                throw new BusinessRuleException('Yalnızca stoktaki açılış motoru iptal edilebilir.');
            }

            $moved = StockMovement::query()->where('reference_type', $locked->getMorphClass())->where('reference_id', $locked->getKey())
                ->where('type', StockMovementType::Shipment->value)->exists();

            if ($moved) {
                throw new BusinessRuleException('Sevk edilmiş motorun açılış kaydı iptal edilemez.');
            }

            if ($locked->warehouse_id !== $opening->warehouse_id) {
                throw new BusinessRuleException('Motor başka depoya aktarılmış; önce giriş deposuna geri aktarın.');
            }

            $this->stock->reverseMotorOpening($opening, $actor, "Açılış iptali ({$locked->serial_number}): {$reason}");

            $locked->status = MotorUnitStatus::Voided;
            $locked->save();
            $unit->setRawAttributes($locked->getAttributes(), sync: true);

            return $unit;
        });
    }

    /**
     * İade gelen motoru stoğa alır (yönetici ve depo, açıklama zorunlu): motor sevk edilmiş olmalı; seçilen depoda
     * yeniden stokta görünür, mamul kartına iade hareketi yazılır, sevk bilgisi iade kaydında saklanır. İade faturası ayrı
     * işlemdir; faturalı motor, iade faturası kesilmeden yeniden faturalanamaz (InvoiceService).
     */
    public function receiveReturn(MotorUnit $unit, Warehouse $warehouse, User $actor, ?string $reason): MotorUnit
    {
        $reason = trim((string) $reason);

        if (! $actor->is_active || ! $actor->can(Permission::MotorUnitsReturn)) {
            throw new BusinessRuleException('Motor iadesi alma yetkiniz yok.');
        }

        if ($reason === '') {
            throw new BusinessRuleException('İade açıklaması zorunludur.');
        }

        return DB::transaction(function () use ($unit, $warehouse, $actor, $reason): MotorUnit {
            $locked = MotorUnit::query()->with('motorModel.finishedPart')->lockForUpdate()->findOrFail($unit->getKey());

            if ($locked->status !== MotorUnitStatus::Shipped) {
                throw new BusinessRuleException("{$locked->serial_number} sevk edilmemiş; yalnızca sevk edilen motorun iadesi alınır.");
            }

            $movement = $this->stock->recordMotorReturn($locked->motorModel->finishedPart, $locked, $actor, "Motor iadesi {$locked->serial_number}: {$reason}", $warehouse);

            $return = new MotorUnitReturn;
            $return->forceFill([
                'motor_unit_id' => $locked->getKey(),
                'warehouse_id' => $warehouse->getKey(),
                'stock_movement_id' => $movement->getKey(),
                'shipped_at' => $locked->shipped_at,
                'shipped_by_user_id' => $locked->shipped_by_user_id,
                'description' => $reason,
                'user_id' => $actor->getKey(),
            ])->save();

            $locked->forceFill([
                'status' => MotorUnitStatus::InStock,
                'warehouse_id' => $warehouse->getKey(),
                'shipped_at' => null,
                'shipped_by_user_id' => null,
            ])->save();

            $unit->setRawAttributes($locked->getAttributes(), sync: true);

            return $unit;
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
                'warehouse_id' => $workOrder->target_warehouse_id,
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
