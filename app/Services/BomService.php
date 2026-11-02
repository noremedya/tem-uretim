<?php

namespace App\Services;

use App\Enums\PartType;
use App\Exceptions\BusinessRuleException;
use App\Models\BomCoProduct;
use App\Models\BomItem;
use App\Models\Part;
use App\Models\WorkOrderMaterial;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;

/**
 * Reçete (bom_items) ve eş ürün (bom_co_products) yönetimi (CLAUDE.md bölüm 5 "Reçete").
 *
 * - Üst kart yarı mamul veya mamul; bileşen endirekt sarf veya mamul olamaz (veritabanı trigger'ı da zorlar).
 * - Fire ya oranla (net üzerinden %) ya birim başına miktarla (brüt = net + fire); isteğe bağlı fire (hurda) kartı.
 * - Eş ürün: ana yarı mamulle aynı işlemde çıkan yarı mamul; kendi reçetesi yoktur, tek ana ürünü vardır.
 * - Döngü yasak (dolaylı dahil). Kontrol, tüm reçete değişikliklerini sıraya sokan bir transaction
 *   kilidi altında yapılır; böylece eşzamanlı A→B ve B→A eklemeleri ikisi birden geçemez.
 */
class BomService
{
    /** Reçete değişikliklerini serileştiren advisory lock anahtarı. */
    private const LOCK_KEY = 4_100_001;

    /**
     * @param  string|int|float|null  $scrapQuantity  Birim başına fire miktarı (verilirse oran 0 olmalı).
     * @param  Part|int|null  $scrapPart  Gerçekleşen firenin girdiği fire (hurda) kartı.
     */
    public function add(Part $parent, Part|int $component, string|int|float $quantityPerUnit, string|int|float|null $scrapRate = 0, string|int|float|null $scrapQuantity = null, Part|int|null $scrapPart = null): BomItem
    {
        return DB::transaction(function () use ($parent, $component, $quantityPerUnit, $scrapRate, $scrapQuantity, $scrapPart): BomItem {
            $this->lock();

            $parent = Part::query()->findOrFail($parent->getKey());
            $component = Part::query()->with('unit')->findOrFail($component instanceof Part ? $component->getKey() : $component);

            $this->ensureParent($parent);

            if ($parent->coProductOf()->exists()) {
                throw new BusinessRuleException("{$parent->code} {$parent->name} bir eş ürün; kendi reçetesi olamaz, ana ürünüyle birlikte üretilir.");
            }
            $this->ensureComponent($component);

            if ($parent->is($component)) {
                throw new BusinessRuleException('Bir kart kendi reçetesine bileşen olarak eklenemez.');
            }

            if ($parent->bomItems()->where('component_part_id', $component->getKey())->exists()) {
                throw new BusinessRuleException("{$component->code} {$component->name} bu reçetede zaten var; miktarını düzenleyin.");
            }

            if ($this->reaches($component->getKey(), $parent->getKey())) {
                throw new BusinessRuleException("{$component->code} {$component->name} eklenemez: reçetesinde dolaylı olarak {$parent->code} {$parent->name} geçiyor (döngü).");
            }

            $item = new BomItem($this->values($parent, $component, $quantityPerUnit, $scrapRate, $scrapQuantity, $scrapPart));
            $item->parent_part_id = $parent->getKey();
            $item->component_part_id = $component->getKey();
            $item->save();

            app(PartWarehouseService::class)->ensure($parent, $component, $item->scrap_part_id);

            return $item;
        });
    }

    /**
     * @param  int  $expectedLockVersion  Düzenlemenin başladığı andaki lock_version.
     */
    public function update(BomItem $item, string|int|float $quantityPerUnit, string|int|float|null $scrapRate, int $expectedLockVersion, string|int|float|null $scrapQuantity = null, Part|int|null $scrapPart = null): BomItem
    {
        return DB::transaction(function () use ($item, $quantityPerUnit, $scrapRate, $expectedLockVersion, $scrapQuantity, $scrapPart): BomItem {
            $component = Part::query()->with('unit')->findOrFail($item->component_part_id);
            $parent = Part::query()->findOrFail($item->parent_part_id);

            $item->fill($this->values($parent, $component, $quantityPerUnit, $scrapRate, $scrapQuantity, $scrapPart));
            $item->saveExpectingVersion($expectedLockVersion);

            app(PartWarehouseService::class)->ensure($item->scrap_part_id);

            return $item;
        });
    }

    /**
     * Reçete satırını kaldırır (silme yasağı ana kayıtlar içindir; değişiklik denetim izine yazılır).
     * Açık iş emirleri kendi malzeme listesini kullandığı için etkilenmez.
     */
    public function remove(BomItem $item): void
    {
        DB::transaction(function () use ($item): void {
            $this->lock();
            $item->delete();
        });
    }

    /**
     * Eş ürün ekler: $parent bir birim üretilirken $coPart'tan $quantityPerUnit kadar çıkar.
     */
    public function addCoProduct(Part $parent, Part|int $coPart, string|int|float $quantityPerUnit): BomCoProduct
    {
        return DB::transaction(function () use ($parent, $coPart, $quantityPerUnit): BomCoProduct {
            $this->lock();

            $parent = Part::query()->findOrFail($parent->getKey());
            $coPart = Part::query()->with('unit')->findOrFail($coPart instanceof Part ? $coPart->getKey() : $coPart);

            if ($parent->type !== PartType::SemiFinished || $coPart->type !== PartType::SemiFinished) {
                throw new BusinessRuleException('Eş ürün ve ana ürünü yarı mamul olmalıdır.');
            }

            if ($parent->is($coPart)) {
                throw new BusinessRuleException('Bir ürün kendi eş ürünü olamaz.');
            }

            if (! $coPart->is_active) {
                throw new BusinessRuleException("{$coPart->code} {$coPart->name} pasif; eş ürün olamaz.");
            }

            if ($parent->coProductOf()->exists()) {
                throw new BusinessRuleException("{$parent->code} {$parent->name} zaten bir eş ürün; eş ürünün eş ürünü olamaz.");
            }

            if ($coPart->bomItems()->exists() || $coPart->coProducts()->exists()) {
                throw new BusinessRuleException("{$coPart->code} {$coPart->name} kendi reçetesi veya eş ürünü olan bir ürün; eş ürün yalnızca ana ürünüyle üretilir.");
            }

            if (($other = BomCoProduct::query()->with('parent')->firstWhere('co_part_id', $coPart->getKey())) !== null) {
                throw new BusinessRuleException("{$coPart->code} {$coPart->name} zaten {$other->parent->code} ürününün eş ürünü.");
            }

            if ($this->reaches($parent->getKey(), $coPart->getKey())) {
                throw new BusinessRuleException("{$coPart->code} {$coPart->name} bu ürünün reçetesinde (dolaylı) kullanılıyor; eş ürün olamaz (döngü).");
            }

            $coProduct = new BomCoProduct;
            $coProduct->forceFill([
                'parent_part_id' => $parent->getKey(),
                'co_part_id' => $coPart->getKey(),
                'quantity_per_unit' => $this->positiveQuantity($coPart, $quantityPerUnit),
            ])->save();

            app(PartWarehouseService::class)->ensure($parent, $coPart);

            return $coProduct;
        });
    }

    public function updateCoProduct(BomCoProduct $coProduct, string|int|float $quantityPerUnit, int $expectedLockVersion): BomCoProduct
    {
        return DB::transaction(function () use ($coProduct, $quantityPerUnit, $expectedLockVersion): BomCoProduct {
            $coProduct->quantity_per_unit = $this->positiveQuantity(Part::query()->with('unit')->findOrFail($coProduct->co_part_id), $quantityPerUnit);
            $coProduct->saveExpectingVersion($expectedLockVersion);

            return $coProduct;
        });
    }

    /** Eş ürünü kaldırır (denetim izine yazılır). Açık iş emirleri kendi çıktı listesini kullanır. */
    public function removeCoProduct(BomCoProduct $coProduct): void
    {
        DB::transaction(function () use ($coProduct): void {
            $this->lock();
            $coProduct->delete();
        });
    }

    /**
     * $source reçetesinin satırlarını $target reçetesine kopyalar; hedefte zaten olan bileşenler atlanır.
     * Eş ürünler kopyalanmaz (bir ürün tek ana ürünün eş ürünü olabilir). Döngü veya kural ihlalinde hiçbiri eklenmez.
     *
     * @return int eklenen satır sayısı
     */
    public function copy(Part $source, Part $target): int
    {
        return DB::transaction(function () use ($source, $target): int {
            $this->lock();

            if ($source->is($target)) {
                throw new BusinessRuleException('Reçete kendine kopyalanamaz.');
            }

            $existing = $target->bomItems()->pluck('component_part_id')->all();
            $items = $source->bomItems()->whereNotIn('component_part_id', [...$existing, $target->getKey()])->orderBy('id')->get();

            if ($items->isEmpty() && $source->bomItems()->doesntExist()) {
                throw new BusinessRuleException("{$source->code} {$source->name} ürününün reçetesi yok.");
            }

            foreach ($items as $item) {
                $this->add($target, $item->component_part_id, (string) $item->quantity_per_unit, (string) $item->scrap_rate, $item->scrap_quantity_per_unit, $item->scrap_part_id);
            }

            return $items->count();
        });
    }

    /**
     * Reçete ağacı ve $quantity adet için ihtiyaç (fire dahil, her seviyede): yarı mamul kendi reçetesiyle açılır; reçetesi
     * olmayan (dışarıdan/fason veya eş ürün) yaprak kalır. Stok hesaba katılmaz (brüt ihtiyaç).
     *
     * @return list<array{item: BomItem, part: Part, depth: int, net: string, scrap: string, total: string, children: list<mixed>}>
     */
    public function tree(Part $part, string|int|float $quantity = 1, int $depth = 0, array $path = []): array
    {
        $quantity = Quantity::parse($quantity);
        $path[] = $part->getKey();

        return $part->bomItems()->with(['component.unit', 'component.bomItems', 'scrapPart'])->orderBy('id')->get()
            ->map(function (BomItem $item) use ($quantity, $depth, $path): array {
                $component = $item->component;
                $net = Quantity::mul($quantity, (string) $item->quantity_per_unit);
                $scrap = $this->scrapFor($net, $quantity, $item, ! $component->unit->allows_decimal);
                $total = Quantity::add($net, $scrap);

                return [
                    'item' => $item,
                    'part' => $component,
                    'depth' => $depth,
                    'net' => $net,
                    'scrap' => $scrap,
                    'total' => $total,
                    // Döngü korumalı (veritabanı zaten engeller).
                    'children' => in_array($component->getKey(), $path, true) ? [] : $this->tree($component, $total, $depth + 1, $path),
                ];
            })
            ->all();
    }

    /**
     * Ağacın yaprakları toplanmış hâli: en alt seviyedeki malzemeler ve toplam ihtiyaç (fire dahil).
     *
     * @return list<array{part: Part, total: string}>
     */
    public function requirements(Part $part, string|int|float $quantity): array
    {
        $totals = [];
        $walk = function (array $nodes) use (&$walk, &$totals): void {
            foreach ($nodes as $node) {
                if ($node['children'] !== []) {
                    $walk($node['children']);

                    continue;
                }

                $id = $node['part']->getKey();
                $totals[$id] = ['part' => $node['part'], 'total' => Quantity::add($totals[$id]['total'] ?? '0', $node['total'])];
            }
        };
        $walk($this->tree($part, $quantity));

        return collect($totals)->sortBy(fn (array $row) => $row['part']->code)->values()->all();
    }

    /** Standart fire: miktarla verilmişse adet × birim başına fire, değilse net × oran; tam sayılı birimde yukarı. */
    public function scrapFor(string $net, string $quantity, BomItem|WorkOrderMaterial $line, bool $wholeUnit): string
    {
        $scrap = $line->scrap_quantity_per_unit !== null
            ? Quantity::mul($quantity, (string) $line->scrap_quantity_per_unit)
            : Quantity::percentOf($net, (string) $line->scrap_rate);

        if (Quantity::isZero($scrap)) {
            return '0.000';
        }

        return Quantity::ceil($scrap, $wholeUnit ? 0 : Quantity::SCALE);
    }

    /**
     * $from kartının reçetesinden (dolaylı dahil) $to kartına ulaşılıyor mu?
     */
    public function reaches(int $from, int $to): bool
    {
        if ($from === $to) {
            return true;
        }

        return (bool) DB::selectOne(<<<'SQL'
            WITH RECURSIVE tree(part_id) AS (
                SELECT component_part_id FROM bom_items WHERE parent_part_id = ?
                UNION
                SELECT b.component_part_id FROM bom_items b JOIN tree t ON b.parent_part_id = t.part_id
            )
            SELECT EXISTS (SELECT 1 FROM tree WHERE part_id = ?) AS found
        SQL, [$from, $to])->found;
    }

    /** Reçete yapısını etkileyen değişiklikleri (satır ekleme/silme, kart türü değişikliği) sıraya sokar. */
    public function lock(): void
    {
        DB::select('SELECT pg_advisory_xact_lock(?)', [self::LOCK_KEY]);
    }

    private function ensureParent(Part $parent): void
    {
        if (! $parent->canHaveBom()) {
            throw new BusinessRuleException('Reçete yalnızca yarı mamul veya mamul kartına tanımlanabilir.');
        }
    }

    private function ensureComponent(Part $component): void
    {
        if (in_array($component->type, [PartType::IndirectConsumable, PartType::FinishedGood], true)) {
            throw new BusinessRuleException("{$component->type->label()} reçeteye bileşen olarak eklenemez.");
        }

        if (! $component->is_active) {
            throw new BusinessRuleException("{$component->code} {$component->name} pasif; reçeteye eklenemez.");
        }
    }

    /** @return array{quantity_per_unit: string, scrap_rate: string, scrap_quantity_per_unit: ?string, scrap_part_id: ?int} */
    private function values(Part $parent, Part $component, string|int|float $quantityPerUnit, string|int|float|null $scrapRate, string|int|float|null $scrapQuantity, Part|int|null $scrapPart): array
    {
        $quantity = $this->positiveQuantity($component, $quantityPerUnit);

        $rate = Quantity::parse($scrapRate ?? 0);

        if (Quantity::compare($rate, '0') < 0 || Quantity::compare($rate, '100') >= 0) {
            throw new BusinessRuleException('Fire oranı 0 ile 100 arasında olmalıdır (100 hariç).');
        }

        $scrapQuantity = $scrapQuantity === null || $scrapQuantity === '' ? null : Quantity::parse($scrapQuantity);

        if ($scrapQuantity !== null) {
            if (Quantity::compare($scrapQuantity, '0') < 0) {
                throw new BusinessRuleException('Fire miktarı negatif olamaz.');
            }

            if (! Quantity::isZero($rate)) {
                throw new BusinessRuleException('Fire ya oranla ya miktarla girilir; ikisi birden girilemez.');
            }
        }

        $scrapPartId = $scrapPart instanceof Part ? $scrapPart->getKey() : $scrapPart;

        if ($scrapPartId !== null) {
            $scrap = Part::query()->findOrFail($scrapPartId);

            if ($scrap->isFinishedGood() || ! $scrap->is_active || $scrap->is($component) || $scrap->is($parent)) {
                throw new BusinessRuleException('Fire kartı aktif olmalı; mamul, malzemenin kendisi veya üretilen ürün olamaz.');
            }

            if ($scrap->unit_id !== $component->unit_id) {
                throw new BusinessRuleException("Fire kartı malzemeyle aynı birimde olmalıdır ({$component->unit->name}).");
            }
        }

        return ['quantity_per_unit' => $quantity, 'scrap_rate' => $rate, 'scrap_quantity_per_unit' => $scrapQuantity, 'scrap_part_id' => $scrapPartId];
    }

    private function positiveQuantity(Part $part, string|int|float $value): string
    {
        $quantity = Quantity::parse($value);

        if (Quantity::compare($quantity, '0') <= 0) {
            throw new BusinessRuleException('Birim başına miktar sıfırdan büyük olmalıdır.');
        }

        if (! $part->unit->allows_decimal && ! Quantity::isWhole($quantity)) {
            throw new BusinessRuleException("{$part->code} {$part->name} için birim başına miktar tam sayı olmalıdır (birim: {$part->unit->name}).");
        }

        return $quantity;
    }
}
