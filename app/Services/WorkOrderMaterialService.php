<?php

namespace App\Services;

use App\Enums\Permission;
use App\Exceptions\BusinessRuleException;
use App\Models\BomItem;
use App\Models\Part;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderMaterial;
use Illuminate\Support\Facades\DB;

/**
 * İş emrinde malzeme değişikliği (karar 2026-11-10): satır eklenir, miktarı/firesi değişir veya çıkarılır; her
 * değişiklikte kullanıcıya sorulur: yalnızca bu iş emri mi, yoksa ürünün reçetesine de işlensin mi (kalıcı).
 * - Planlandı veya üretimde iş emrinde ve henüz üretim kaydı yokken (kayıtlar birikimli hesaplandığı için; önce kayıtlar geri alınır).
 * - İş emri yönetme yetkisi; kalıcı değişiklik ayrıca reçete (motor modeli yönetme) yetkisi ister ve BomService kurallarıyla
 *   (döngü, tür, birim) reçeteye yazılır. Diğer açık iş emirleri etkilenmez.
 * - Sürüm kontrolü: iş emrinin lock_version'ı (formun açıldığı an) kullanılır. Değişiklik iş emrinin denetim izine yazılır.
 */
class WorkOrderMaterialService
{
    public function __construct(
        private readonly WorkOrderService $orders,
        private readonly BomService $boms,
    ) {}

    /**
     * @param  array{part_id?: int|string|null, quantity_per_unit: mixed, scrap_rate?: mixed, scrap_quantity_per_unit?: mixed}  $data
     */
    public function save(WorkOrder $workOrder, ?WorkOrderMaterial $material, array $data, bool $permanent, User $actor, int $expectedLockVersion): WorkOrderMaterial
    {
        return DB::transaction(function () use ($workOrder, $material, $data, $permanent, $actor, $expectedLockVersion): WorkOrderMaterial {
            $workOrder = $this->lock($workOrder, $permanent, $actor, $expectedLockVersion);
            $product = $workOrder->product()->firstOrFail();
            $component = Part::query()->with('unit')->findOrFail($material?->part_id ?? (int) ($data['part_id'] ?? 0));

            if ($material === null) {
                $this->boms->ensureComponent($component);

                if ($component->is($product)) {
                    throw new BusinessRuleException('Üretilen ürün kendi malzemesi olamaz.');
                }

                if ($workOrder->materials()->where('part_id', $component->getKey())->exists()) {
                    throw new BusinessRuleException("{$component->code} {$component->name} bu iş emrinde zaten var; miktarını değiştirin.");
                }

                if ($this->boms->reaches($component->getKey(), $product->getKey())) {
                    throw new BusinessRuleException("{$component->code} {$component->name} eklenemez: reçetesinde dolaylı olarak {$product->code} geçiyor (döngü).");
                }
            }

            $values = $this->boms->values($product, $component, $data['quantity_per_unit'] ?? '', $data['scrap_rate'] ?? 0,
                $data['scrap_quantity_per_unit'] ?? null, $material?->scrap_part_id);

            if ($permanent) {
                $bomItem = BomItem::query()->where('parent_part_id', $product->getKey())->where('component_part_id', $component->getKey())->first();
                $bomItem === null
                    ? $this->boms->add($product, $component, $values['quantity_per_unit'], $values['scrap_rate'], $values['scrap_quantity_per_unit'], $values['scrap_part_id'])
                    : $this->boms->update($bomItem, $values['quantity_per_unit'], $values['scrap_rate'], $bomItem->lock_version, $values['scrap_quantity_per_unit'], $values['scrap_part_id']);
            }

            $old = $material?->only(['quantity_per_unit', 'scrap_rate', 'scrap_quantity_per_unit']);
            $material ??= new WorkOrderMaterial;
            $material->forceFill([
                'work_order_id' => $workOrder->getKey(),
                'part_id' => $component->getKey(),
                'warehouse_id' => $material->warehouse_id ?? $this->orders->materialWarehouseId($workOrder, $component),
                ...$values,
            ]);
            $this->orders->calculateMaterial($material, (string) $workOrder->quantity, $component);
            $material->save();

            $this->log($workOrder, $actor, $old === null ? 'Malzeme eklendi' : 'Malzeme değiştirildi', $component, $permanent,
                ['quantity_per_unit' => $values['quantity_per_unit'], 'scrap_rate' => $values['scrap_rate'], 'scrap_quantity_per_unit' => $values['scrap_quantity_per_unit']], $old);

            return $material;
        });
    }

    public function remove(WorkOrder $workOrder, WorkOrderMaterial $material, bool $permanent, User $actor, int $expectedLockVersion): void
    {
        DB::transaction(function () use ($workOrder, $material, $permanent, $actor, $expectedLockVersion): void {
            $workOrder = $this->lock($workOrder, $permanent, $actor, $expectedLockVersion);

            if ($workOrder->materials()->count() <= 1) {
                throw new BusinessRuleException('İş emrinin son malzemesi çıkarılamaz.');
            }

            $component = $material->part()->firstOrFail();

            if ($permanent) {
                $bomItem = BomItem::query()->where('parent_part_id', $workOrder->product_part_id)->where('component_part_id', $component->getKey())->first();
                $bomItem !== null && $this->boms->remove($bomItem);
            }

            $old = $material->only(['quantity_per_unit', 'scrap_rate', 'scrap_quantity_per_unit']);
            $material->delete();
            $this->log($workOrder, $actor, 'Malzeme çıkarıldı', $component, $permanent, [], $old);
        });
    }

    private function lock(WorkOrder $workOrder, bool $permanent, User $actor, int $expectedLockVersion): WorkOrder
    {
        if (! $actor->can(Permission::WorkOrdersManage)) {
            throw new BusinessRuleException('İş emri malzemesini değiştirme yetkiniz yok.');
        }

        if ($permanent && ! $actor->can(Permission::MotorModelsManage)) {
            throw new BusinessRuleException('Reçeteyi değiştirme yetkiniz yok; değişikliği yalnızca bu iş emri için yapabilirsiniz.');
        }

        $locked = WorkOrder::query()->lockForUpdate()->findOrFail($workOrder->getKey());

        if (! $locked->status->isOpen()) {
            throw new BusinessRuleException('Yalnızca planlandı veya üretimdeki iş emrinin malzemesi değiştirilir.');
        }

        if ($locked->hasProduction()) {
            throw new BusinessRuleException('Üretim kaydı olan iş emrinde malzeme değiştirilemez; önce üretim kayıtlarını geri alın.');
        }

        // Sürüm kontrolü (tek atomik sorgu): başka biri iş emrini değiştirdiyse StaleModelException.
        $locked->saveExpectingVersion($expectedLockVersion);
        $workOrder->setRawAttributes($locked->getAttributes(), sync: true);

        return $locked;
    }

    /** @param array<string, mixed> $new @param array<string, mixed>|null $old */
    private function log(WorkOrder $workOrder, User $actor, string $what, Part $component, bool $permanent, array $new, ?array $old): void
    {
        activity()
            ->performedOn($workOrder)
            ->causedBy($actor)
            ->event('updated')
            ->withProperties(['material' => "{$component->code} {$component->name}", 'scope' => $permanent ? 'Reçeteye de işlendi' : 'Yalnızca bu iş emri',
                'attributes' => $new, 'old' => $old ?? []])
            ->log("{$what}: {$component->code} ({$component->name}) — ".($permanent ? 'reçeteye de işlendi' : 'yalnızca bu iş emri'));
    }
}
