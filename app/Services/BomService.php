<?php

namespace App\Services;

use App\Enums\PartType;
use App\Exceptions\BusinessRuleException;
use App\Models\BomItem;
use App\Models\Part;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;

/**
 * Reçete (bom_items) yönetimi (CLAUDE.md bölüm 5 "Reçete").
 *
 * - Üst kart yarı mamul veya mamul; bileşen endirekt sarf veya mamul olamaz (veritabanı trigger'ı da zorlar).
 * - Döngü yasak (dolaylı dahil). Kontrol, tüm reçete değişikliklerini sıraya sokan bir transaction
 *   kilidi altında yapılır; böylece eşzamanlı A→B ve B→A eklemeleri ikisi birden geçemez.
 */
class BomService
{
    /** Reçete değişikliklerini serileştiren advisory lock anahtarı. */
    private const LOCK_KEY = 4_100_001;

    public function add(Part $parent, Part|int $component, string|int|float $quantityPerUnit, string|int|float|null $scrapRate = 0): BomItem
    {
        return DB::transaction(function () use ($parent, $component, $quantityPerUnit, $scrapRate): BomItem {
            $this->lock();

            $parent = Part::query()->findOrFail($parent->getKey());
            $component = Part::query()->with('unit')->findOrFail($component instanceof Part ? $component->getKey() : $component);

            $this->ensureParent($parent);
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

            $item = new BomItem($this->values($component, $quantityPerUnit, $scrapRate));
            $item->parent_part_id = $parent->getKey();
            $item->component_part_id = $component->getKey();
            $item->save();

            return $item;
        });
    }

    /**
     * @param  int  $expectedLockVersion  Düzenlemenin başladığı andaki lock_version.
     */
    public function update(BomItem $item, string|int|float $quantityPerUnit, string|int|float|null $scrapRate, int $expectedLockVersion): BomItem
    {
        return DB::transaction(function () use ($item, $quantityPerUnit, $scrapRate, $expectedLockVersion): BomItem {
            $component = Part::query()->with('unit')->findOrFail($item->component_part_id);

            $item->fill($this->values($component, $quantityPerUnit, $scrapRate));
            $item->saveExpectingVersion($expectedLockVersion);

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

    /** @return array{quantity_per_unit: string, scrap_rate: string} */
    private function values(Part $component, string|int|float $quantityPerUnit, string|int|float|null $scrapRate): array
    {
        $quantity = Quantity::parse($quantityPerUnit);

        if (Quantity::compare($quantity, '0') <= 0) {
            throw new BusinessRuleException('Birim başına miktar sıfırdan büyük olmalıdır.');
        }

        if (! $component->unit->allows_decimal && ! Quantity::isWhole($quantity)) {
            throw new BusinessRuleException("{$component->code} {$component->name} için birim başına miktar tam sayı olmalıdır (birim: {$component->unit->name}).");
        }

        $rate = Quantity::parse($scrapRate ?? 0);

        if (Quantity::compare($rate, '0') < 0 || Quantity::compare($rate, '100') >= 0) {
            throw new BusinessRuleException('Fire oranı 0 ile 100 arasında olmalıdır (100 hariç).');
        }

        return ['quantity_per_unit' => $quantity, 'scrap_rate' => $rate];
    }
}
