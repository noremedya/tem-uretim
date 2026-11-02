<?php

namespace App\Services;

use App\Enums\PartType;
use App\Exceptions\BusinessRuleException;
use App\Models\Part;
use App\Models\PartSubtype;
use App\Models\StockBalance;
use App\Models\Warehouse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Depo yönetimi ve ürünün varsayılan deposu. Bakiye burada değişmez; yalnızca StockService değiştirir.
 *
 * Varsayılan depo: ürünün kendi varsayılan deposu (parts.default_warehouse_id), yoksa alt türünün deposu
 * (part_subtypes.default_warehouse_id), yoksa türünün varsayılan deposu (warehouses.default_part_types). Her tür en
 * fazla bir aktif depoda varsayılandır.
 */
class WarehouseService
{
    private const FIELDS = ['code', 'name', 'allows_negative', 'is_production', 'is_shipping', 'default_part_types'];

    /** @var array<string, int>|null tür => depo id (istek boyunca önbellek) */
    private ?array $typeMap = null;

    public function create(array $data): Warehouse
    {
        return DB::transaction(function () use ($data): Warehouse {
            $warehouse = new Warehouse(Arr::only($this->normalize($data), self::FIELDS));
            $this->ensureValid($warehouse);
            $this->releaseExclusiveFlags($warehouse);
            $warehouse->save();

            return $warehouse;
        });
    }

    /**
     * @param  int  $expectedLockVersion  Düzenlemenin başladığı andaki lock_version.
     */
    public function update(Warehouse $warehouse, array $data, int $expectedLockVersion): Warehouse
    {
        return DB::transaction(function () use ($warehouse, $data, $expectedLockVersion): Warehouse {
            $warehouse->fill(Arr::only($this->normalize($data), self::FIELDS));
            $this->ensureValid($warehouse);

            // Eksiye düşmeye izin kaldırılıyorsa depoda eksi bakiye kalmamalı.
            if ($warehouse->isDirty('allows_negative') && ! $warehouse->allows_negative
                && $warehouse->stockBalances()->where('quantity', '<', 0)->lockForUpdate()->exists()) {
                throw new BusinessRuleException('Bu depoda eksi bakiyeli ürünler var; önce sayım veya giriş yapın.');
            }

            $this->releaseExclusiveFlags($warehouse);
            $warehouse->saveExpectingVersion($expectedLockVersion);

            return $warehouse;
        });
    }

    /** Pasif depoya giriş yapılmaz; yalnızca sayım ve başka depoya transfer. Varsayılan depo pasifleştirilemez. */
    public function deactivate(Warehouse $warehouse): Warehouse
    {
        return DB::transaction(function () use ($warehouse): Warehouse {
            $warehouse = Warehouse::query()->lockForUpdate()->findOrFail($warehouse->getKey());

            if ($warehouse->default_part_types !== [] || $warehouse->is_production || $warehouse->is_shipping) {
                throw new BusinessRuleException('Bu depo varsayılan depo (ürün türü, üretim veya sevkiyat); önce bu görevleri başka bir depoya verin.');
            }

            if (Part::query()->where('default_warehouse_id', $warehouse->getKey())->exists()) {
                throw new BusinessRuleException('Bazı ürünlerin varsayılan deposu bu depo; önce ürünlerin varsayılan deposunu değiştirin.');
            }

            if (PartSubtype::query()->where('default_warehouse_id', $warehouse->getKey())->exists()) {
                throw new BusinessRuleException('Bazı alt türlerin varsayılan deposu bu depo; önce alt türlerin varsayılan deposunu değiştirin.');
            }

            if (StockBalance::query()->where('warehouse_id', $warehouse->getKey())->where('quantity', '<>', 0)->exists()) {
                throw new BusinessRuleException('Bu depoda stok var; önce başka depoya transfer edin veya sayımla sıfırlayın.');
            }

            $warehouse->is_active = false;
            $warehouse->save();

            return $warehouse;
        });
    }

    public function activate(Warehouse $warehouse): Warehouse
    {
        $warehouse->is_active = true;
        $warehouse->save();

        return $warehouse;
    }

    /** Ürünün varsayılan deposu (stok işleminde depo seçilmezse kullanılır). */
    public function defaultFor(Part $part): Warehouse
    {
        $id = $this->defaultIdFor($part);

        if ($id === null) {
            throw new BusinessRuleException("{$part->code} {$part->name} için varsayılan depo tanımlı değil; depolar ekranında \"{$part->type->label()}\" türünü bir depoya atayın.");
        }

        return Warehouse::query()->findOrFail($id);
    }

    public function defaultIdFor(Part $part): ?int
    {
        return $part->default_warehouse_id ?? $this->defaultIdForSubtype($part->subtype_id) ?? $this->defaultIdForType($part->type);
    }

    private function defaultIdForSubtype(?int $subtypeId): ?int
    {
        return $subtypeId === null ? null : PartSubtype::query()->whereKey($subtypeId)->value('default_warehouse_id');
    }

    /** Ürüne ait depo var mı? (Ürün deposu ayarıyla açılır; malzeme her zaman oradan düşer.) */
    public function hasOwnWarehouse(Part $part): bool
    {
        return Warehouse::query()->where('part_id', $part->getKey())->exists();
    }

    public function defaultIdForType(PartType $type): ?int
    {
        $this->typeMap ??= Warehouse::query()->where('is_active', true)->orderBy('id')->get(['id', 'default_part_types'])
            ->flatMap(fn (Warehouse $w) => array_fill_keys($w->default_part_types ?? [], $w->getKey()))
            ->all();

        return $this->typeMap[$type->value] ?? null;
    }

    public function production(): ?Warehouse
    {
        return Warehouse::query()->where('is_production', true)->where('is_active', true)->first();
    }

    public function shipping(): ?Warehouse
    {
        return Warehouse::query()->where('is_shipping', true)->where('is_active', true)->first();
    }

    public function flush(): void
    {
        $this->typeMap = null;
    }

    private function ensureValid(Warehouse $warehouse): void
    {
        if ($warehouse->code === '' || $warehouse->name === '') {
            throw new BusinessRuleException('Depo kodu ve adı zorunludur.');
        }

        if (Warehouse::query()->where('code', $warehouse->code)->when($warehouse->exists, fn ($q) => $q->whereKeyNot($warehouse->getKey()))->exists()) {
            throw new BusinessRuleException("\"{$warehouse->code}\" kodlu bir depo zaten var.");
        }

        if (! $warehouse->is_active && ($warehouse->default_part_types !== [] || $warehouse->is_production || $warehouse->is_shipping)) {
            throw new BusinessRuleException('Pasif depo varsayılan, üretim veya sevkiyat deposu olamaz.');
        }

        // Her tür en fazla bir depoda varsayılan.
        foreach ($warehouse->default_part_types as $type) {
            $other = Warehouse::query()
                ->whereRaw('default_part_types @> ?::jsonb', [json_encode([$type])])
                ->when($warehouse->exists, fn ($q) => $q->whereKeyNot($warehouse->getKey()))
                ->first();

            if ($other !== null) {
                throw new BusinessRuleException(sprintf('"%s" türünün varsayılan deposu zaten %s; önce oradan kaldırın.', PartType::from($type)->label(), $other->name));
            }
        }
    }

    /** Üretim ve sevkiyat deposu tektir: bu depo işaretlenince diğerinden kaldırılır. */
    private function releaseExclusiveFlags(Warehouse $warehouse): void
    {
        foreach (['is_production', 'is_shipping'] as $flag) {
            if ($warehouse->{$flag} && $warehouse->isDirty($flag)) {
                Warehouse::query()->where($flag, true)->when($warehouse->exists, fn ($q) => $q->whereKeyNot($warehouse->getKey()))
                    ->get()->each(function (Warehouse $other) use ($flag): void {
                        $other->{$flag} = false;
                        $other->save();
                    });
            }
        }
    }

    private function normalize(array $data): array
    {
        if (array_key_exists('default_part_types', $data)) {
            $types = array_values(array_unique(array_map(
                fn ($type) => $type instanceof PartType ? $type->value : (string) $type,
                (array) ($data['default_part_types'] ?? []),
            )));

            foreach ($types as $type) {
                if (PartType::tryFrom($type) === null) {
                    throw new BusinessRuleException('Geçersiz ürün türü.');
                }
            }

            $data['default_part_types'] = $types;
        }

        foreach (['allows_negative', 'is_production', 'is_shipping'] as $flag) {
            if (array_key_exists($flag, $data)) {
                $data[$flag] = (bool) $data[$flag];
            }
        }

        return $data;
    }
}
