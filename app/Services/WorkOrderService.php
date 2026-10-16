<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\WorkOrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\OrderItem;
use App\Models\Part;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderMaterial;
use App\Models\WorkOrderStatusHistory;
use App\Support\Quantity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * İş emirleri (CLAUDE.md bölüm 5 "İş emri"). Tamamlama ve geri alma ayrı akışlardır (Aşama 4c/4d).
 */
class WorkOrderService
{
    private const EDITABLE_FIELDS = ['assigned_user_id', 'planned_start', 'planned_end', 'production_batch_id', 'notes'];

    public function __construct(private readonly NumberSequenceService $numbers) {}

    /**
     * @param  array{product_part_id: int, quantity: mixed, order_item_id?: ?int, assigned_user_id?: ?int, planned_start?: mixed, planned_end: mixed, production_batch_id?: ?int, notes?: ?string}  $data
     */
    public function create(array $data, User $actor): WorkOrder
    {
        $data = $this->normalize($data);

        return DB::transaction(function () use ($data, $actor): WorkOrder {
            $product = Part::query()->with(['unit', 'motorModel'])->findOrFail($data['product_part_id'] ?? 0);

            if (! $product->canHaveBom()) {
                throw new BusinessRuleException('İş emri yalnızca yarı mamul veya mamul üretir.');
            }

            if (! $product->is_active) {
                throw new BusinessRuleException("{$product->code} {$product->name} pasif; iş emri açılamaz.");
            }

            $quantity = $this->quantity($product, $data['quantity'] ?? null);

            $workOrder = new WorkOrder;
            $workOrder->forceFill([
                ...Arr::only($data, self::EDITABLE_FIELDS),
                'product_part_id' => $product->getKey(),
                'motor_model_id' => $product->isFinishedGood() ? $product->motorModel->getKey() : null,
                'quantity' => $quantity,
                'status' => WorkOrderStatus::Planned,
            ]);

            $this->ensureValid($workOrder);

            if (filled($data['order_item_id'] ?? null)) {
                $workOrder->order_item_id = (int) $data['order_item_id'];
                $this->ensureOrderItemQuota($workOrder);
            }

            $workOrder->work_order_number = $this->numbers->next('work_order');
            $workOrder->save();

            $this->copyMaterials($workOrder, $product);
            $this->recordHistory($workOrder, null, WorkOrderStatus::Planned, $actor);

            return $workOrder;
        });
    }

    /**
     * Açık iş emrinin atama, tarih, parti, not ve adet bilgilerini değiştirir. Sipariş kalemi yalnızca
     * planlandı durumunda değişir. Adet değişince malzeme ihtiyacı yeniden hesaplanır.
     *
     * @param  int  $expectedLockVersion  Düzenlemenin başladığı andaki lock_version.
     */
    public function update(WorkOrder $workOrder, array $data, int $expectedLockVersion): WorkOrder
    {
        $data = $this->normalize($data);

        return DB::transaction(function () use ($workOrder, $data, $expectedLockVersion): WorkOrder {
            if (! $workOrder->status->isOpen()) {
                throw new BusinessRuleException("{$workOrder->status->label()} durumundaki iş emri düzenlenemez.");
            }

            $workOrder->forceFill(Arr::only($data, self::EDITABLE_FIELDS));

            if (array_key_exists('quantity', $data)) {
                $workOrder->quantity = $this->quantity($workOrder->product()->with('unit')->firstOrFail(), $data['quantity']);
            }

            if (array_key_exists('order_item_id', $data)) {
                $orderItemId = filled($data['order_item_id']) ? (int) $data['order_item_id'] : null;

                if ($orderItemId !== $workOrder->order_item_id && $workOrder->status !== WorkOrderStatus::Planned) {
                    throw new BusinessRuleException('Sipariş bağlantısı yalnızca planlandı durumunda değiştirilebilir.');
                }

                $workOrder->order_item_id = $orderItemId;
            }

            $this->ensureValid($workOrder);

            if ($workOrder->order_item_id !== null && $workOrder->isDirty(['order_item_id', 'quantity'])) {
                $this->ensureOrderItemQuota($workOrder);
            }

            $quantityChanged = $workOrder->isDirty('quantity');

            $workOrder->saveExpectingVersion($expectedLockVersion);

            if ($quantityChanged) {
                $this->recalculateMaterials($workOrder);
            }

            return $workOrder;
        });
    }

    /**
     * Planlandı durumundaki iş emrinin malzeme listesini güncel reçeteden yeniden kopyalar.
     *
     * @param  int  $expectedLockVersion  Sayfanın açıldığı andaki lock_version.
     */
    public function refreshMaterials(WorkOrder $workOrder, int $expectedLockVersion): WorkOrder
    {
        return DB::transaction(function () use ($workOrder, $expectedLockVersion): WorkOrder {
            if ($workOrder->status !== WorkOrderStatus::Planned) {
                throw new BusinessRuleException('Reçete yalnızca planlandı durumundaki iş emrinde yenilenebilir.');
            }

            // Sürüm kontrolü ve satır kilidi: aynı anda başlatma veya düzenleme ile çakışmaz.
            $workOrder->saveExpectingVersion($expectedLockVersion);

            $workOrder->materials()->delete();
            $this->copyMaterials($workOrder, $workOrder->product()->firstOrFail());

            return $workOrder;
        });
    }

    /** Başlat: planlandı → üretimde (atanan operatör veya yönetici). */
    public function start(WorkOrder $workOrder, User $actor): WorkOrder
    {
        return $this->changeStatus($workOrder, WorkOrderStatus::InProgress, $actor);
    }

    /** Planlamaya geri al: üretimde → planlandı (yalnızca yönetici, açıklama zorunlu). */
    public function revertToPlanned(WorkOrder $workOrder, User $actor, ?string $reason): WorkOrder
    {
        return $this->changeStatus($workOrder, WorkOrderStatus::Planned, $actor, $this->requiredReason($reason, 'Planlamaya geri alma'));
    }

    /** İptal: planlandı → iptal (yalnızca yönetici, açıklama zorunlu). */
    public function cancel(WorkOrder $workOrder, User $actor, ?string $reason): WorkOrder
    {
        return $this->changeStatus($workOrder, WorkOrderStatus::Cancelled, $actor, $this->requiredReason($reason, 'İptal'));
    }

    /**
     * Kullanıcı bu iş emrini $to durumuna geçirebilir mi? (Durum geçişinin izinli olması ayrıca kontrol edilir.)
     * - Başlatma/tamamlama: yönetici veya iş emrine atanan operatör. Atanmamış iş emri yalnızca yönetici.
     * - Planlamaya geri alma, iptal, tamamlanmışı geri alma: yalnızca yönetici.
     */
    public function canTransition(User $actor, WorkOrder $workOrder, WorkOrderStatus $to): bool
    {
        if (! $actor->is_active || ! $workOrder->status->canTransitionTo($to)) {
            return false;
        }

        return match ([$workOrder->status, $to]) {
            [WorkOrderStatus::Planned, WorkOrderStatus::InProgress],
            [WorkOrderStatus::InProgress, WorkOrderStatus::Completed] => $actor->can(Permission::WorkOrdersChangeStatusAny)
                || ($actor->can(Permission::WorkOrdersChangeStatus) && $workOrder->isAssignedTo($actor)),
            default => $actor->can(Permission::WorkOrdersManage),
        };
    }

    /**
     * Malzeme durumu (yetersiz malzeme uyarısı): her satır için fire dahil ihtiyaç, mevcut ve eksik.
     * Uyarıdır; başka iş emirlerinin ihtiyacını hesaba katmaz.
     *
     * @return list<array{material: WorkOrderMaterial, required: string, available: string, missing: string}>
     */
    public function materialStatus(WorkOrder $workOrder): array
    {
        return $workOrder->materials()->with(['part.unit', 'part.stockBalance'])->orderBy('id')->get()
            ->map(function (WorkOrderMaterial $material): array {
                $required = $material->totalRequirement();
                $available = (string) ($material->part->stockBalance?->quantity ?? '0');
                $missing = Quantity::sub($required, $available);

                return [
                    'material' => $material,
                    'required' => $required,
                    'available' => $available,
                    'missing' => Quantity::compare($missing, '0') > 0 ? $missing : '0.000',
                ];
            })
            ->all();
    }

    /** Standart fire = ihtiyaç × oran / 100; tam sayılı birimde tam sayıya, diğerlerinde 3 ondalığa yukarı yuvarlanır. */
    public function standardScrap(string $required, string $scrapRate, bool $wholeUnit): string
    {
        if (Quantity::isZero($scrapRate)) {
            return '0.000';
        }

        return Quantity::ceil(Quantity::percentOf($required, $scrapRate), $wholeUnit ? 0 : Quantity::SCALE);
    }

    private function changeStatus(WorkOrder $workOrder, WorkOrderStatus $to, User $actor, ?string $reason = null): WorkOrder
    {
        return DB::transaction(function () use ($workOrder, $to, $actor, $reason): WorkOrder {
            // Satır kilitlenir ve durum kilit altında okunur: iki kullanıcı aynı geçişi aynı anda yapamaz.
            $locked = WorkOrder::query()->lockForUpdate()->findOrFail($workOrder->getKey());
            $from = $locked->status;

            $from->ensureCanTransitionTo($to);

            if (! $this->canTransition($actor, $locked, $to)) {
                throw new BusinessRuleException('Bu iş emrinde bu işlemi yapma yetkiniz yok.');
            }

            $locked->status = $to;

            if ($to === WorkOrderStatus::InProgress && $locked->started_at === null) {
                $locked->started_at = now();
            }

            if ($to === WorkOrderStatus::Cancelled) {
                $locked->cancellation_reason = $reason;
            }

            $locked->save();
            $this->recordHistory($locked, $from, $to, $actor, $reason);

            $workOrder->setRawAttributes($locked->getAttributes(), sync: true);

            return $workOrder;
        });
    }

    private function copyMaterials(WorkOrder $workOrder, Part $product): void
    {
        $items = $product->bomItems()->with('component.unit')->orderBy('id')->get();

        if ($items->isEmpty()) {
            throw new BusinessRuleException("{$product->code} {$product->name} için reçete tanımlı değil; önce reçeteyi girin.");
        }

        $inactive = $items->filter(fn ($item) => ! $item->component->is_active)->map(fn ($item) => $item->component->code);

        if ($inactive->isNotEmpty()) {
            throw new BusinessRuleException('Reçetede pasif bileşen var, iş emri açılamaz: '.$inactive->implode(', '));
        }

        foreach ($items as $item) {
            $material = new WorkOrderMaterial;
            $material->forceFill([
                'work_order_id' => $workOrder->getKey(),
                'part_id' => $item->component_part_id,
                'quantity_per_unit' => (string) $item->quantity_per_unit,
                'scrap_rate' => (string) $item->scrap_rate,
            ]);
            $this->calculateMaterial($material, (string) $workOrder->quantity, $item->component);
            $material->save();
        }
    }

    private function recalculateMaterials(WorkOrder $workOrder): void
    {
        foreach ($workOrder->materials()->with('part.unit')->get() as $material) {
            $this->calculateMaterial($material, (string) $workOrder->quantity, $material->part);
            $material->save();
        }
    }

    private function calculateMaterial(WorkOrderMaterial $material, string $quantity, Part $component): void
    {
        $required = Quantity::mul($quantity, (string) $material->quantity_per_unit);
        $wholeUnit = ! $component->unit->allows_decimal;

        if (Quantity::isZero($required) || ($wholeUnit && ! Quantity::isWhole($required))) {
            throw new BusinessRuleException(sprintf(
                '%s %s ihtiyacı (%s) bu birimde (%s) kullanılamaz; iş emri adedini veya reçeteyi kontrol edin.',
                $component->code,
                $component->name,
                Quantity::format($required),
                $component->unit->name,
            ));
        }

        $material->required_quantity = $required;
        $material->planned_scrap = $this->standardScrap($required, (string) $material->scrap_rate, $wholeUnit);
    }

    /**
     * Sipariş kalemi kotası: iptal edilmemiş iş emirlerinin toplamı kalem adedini aşamaz. Kalem satırı
     * kilitlenir; OrderService kalemi değiştirirken aynı satırı kilitler.
     */
    private function ensureOrderItemQuota(WorkOrder $workOrder): void
    {
        $item = OrderItem::query()->with('order')->lockForUpdate()->findOrFail($workOrder->order_item_id);

        if (! in_array($item->order->status, [OrderStatus::Open, OrderStatus::Partial], true)) {
            throw new BusinessRuleException("{$item->order->order_number} {$item->order->status->label()}; kalemine iş emri bağlanamaz.");
        }

        if ($item->motor_model_id !== $workOrder->motor_model_id) {
            throw new BusinessRuleException('Sipariş kaleminin motor modeli iş emrininkiyle aynı olmalıdır.');
        }

        $used = (string) WorkOrder::query()
            ->where('order_item_id', $item->getKey())
            ->where('status', '!=', WorkOrderStatus::Cancelled->value)
            ->when($workOrder->exists, fn ($query) => $query->whereKeyNot($workOrder->getKey()))
            ->sum('quantity');

        $remaining = Quantity::sub((string) $item->quantity, $used);

        if (Quantity::compare((string) $workOrder->quantity, $remaining) > 0) {
            throw new BusinessRuleException(sprintf(
                'Sipariş kalemi kotası aşılıyor: kalem %s adet, diğer iş emirlerinde %s adet, kalan %s adet.',
                Quantity::format((string) $item->quantity),
                Quantity::format($used),
                Quantity::format($remaining),
            ));
        }
    }

    private function ensureValid(WorkOrder $workOrder): void
    {
        if ($workOrder->planned_end === null) {
            throw new BusinessRuleException('Planlanan bitiş tarihi zorunludur.');
        }

        if ($workOrder->planned_start !== null && $workOrder->planned_end->lt($workOrder->planned_start)) {
            throw new BusinessRuleException('Planlanan bitiş, planlanan başlangıçtan önce olamaz.');
        }

        if ($workOrder->isDirty('assigned_user_id') && $workOrder->assigned_user_id !== null) {
            $user = User::query()->find($workOrder->assigned_user_id);

            if ($user === null || ! $user->is_active || ! $user->hasRole(Role::Operator)) {
                throw new BusinessRuleException('Yalnızca aktif saha operatörleri atanabilir.');
            }
        }
    }

    private function quantity(Part $product, mixed $value): string
    {
        $quantity = Quantity::parse($value ?? '');

        if (Quantity::compare($quantity, '0') <= 0) {
            throw new BusinessRuleException('İş emri adedi sıfırdan büyük olmalıdır.');
        }

        if (! $product->unit->allows_decimal && ! Quantity::isWhole($quantity)) {
            throw new BusinessRuleException("İş emri adedi tam sayı olmalıdır (birim: {$product->unit->name}).");
        }

        return $quantity;
    }

    private function requiredReason(?string $reason, string $action): string
    {
        $reason = trim((string) $reason);

        if ($reason === '') {
            throw new BusinessRuleException("{$action} açıklaması zorunludur.");
        }

        return $reason;
    }

    private function recordHistory(WorkOrder $workOrder, ?WorkOrderStatus $from, WorkOrderStatus $to, User $actor, ?string $description = null): void
    {
        $history = new WorkOrderStatusHistory;
        $history->forceFill([
            'work_order_id' => $workOrder->getKey(),
            'from_status' => $from,
            'to_status' => $to,
            'user_id' => $actor->getKey(),
            'description' => $description,
        ])->save();
    }

    private function normalize(array $data): array
    {
        foreach (['assigned_user_id', 'production_batch_id', 'order_item_id'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = filled($data[$field]) ? (int) $data[$field] : null;
            }
        }

        foreach (['planned_start', 'planned_end'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = filled($data[$field]) ? CarbonImmutable::parse($data[$field])->startOfDay() : null;
            }
        }

        if (array_key_exists('notes', $data)) {
            $data['notes'] = filled($data['notes']) ? trim($data['notes']) : null;
        }

        return $data;
    }
}
