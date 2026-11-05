<?php

namespace App\Services;

use App\Enums\PartType;
use App\Exceptions\BusinessRuleException;
use App\Models\MotorDesign;
use App\Models\MotorDesignItem;
use App\Models\Part;
use App\Models\Unit;
use App\Support\Quantity;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Tasarımlar (deneme reçetesi). Stok, iş emri ve motor modeliyle bağı yoktur: satırlar serbestçe eklenir, değiştirilir
 * ve silinir. Satır tanımlı bir ürün (reçetedeki gibi: mamul ve endirekt sarf olamaz) ya da kartı henüz açılmamış
 * deneme bileşeni (ad + birim) olabilir. Tasarım silinmez, pasifleştirilir; pasif tasarımın satırları değişmez.
 */
class MotorDesignService
{
    private const FIELDS = ['code', 'name', 'description'];

    public function create(array $data): MotorDesign
    {
        return DB::transaction(function () use ($data): MotorDesign {
            $design = new MotorDesign(Arr::only($this->normalize($data), self::FIELDS));
            $this->ensureValid($design);
            $design->save();

            return $design;
        });
    }

    /**
     * @param  int  $expectedLockVersion  Düzenlemenin başladığı andaki lock_version.
     */
    public function update(MotorDesign $design, array $data, int $expectedLockVersion): MotorDesign
    {
        return DB::transaction(function () use ($design, $data, $expectedLockVersion): MotorDesign {
            $design->fill(Arr::only($this->normalize($data), self::FIELDS));
            $this->ensureValid($design);
            $design->saveExpectingVersion($expectedLockVersion);

            return $design;
        });
    }

    public function deactivate(MotorDesign $design): MotorDesign
    {
        $design->is_active = false;
        $design->save();

        return $design;
    }

    public function activate(MotorDesign $design): MotorDesign
    {
        $design->is_active = true;
        $design->save();

        return $design;
    }

    /**
     * @param  array{part_id?: ?int, description?: ?string, quantity: mixed, unit_id?: ?int, notes?: ?string}  $data
     */
    public function addItem(MotorDesign $design, array $data): MotorDesignItem
    {
        return DB::transaction(function () use ($design, $data): MotorDesignItem {
            $this->lockActive($design);

            $item = new MotorDesignItem;
            $item->motor_design_id = $design->getKey();
            $this->fillItem($item, $data);
            $item->save();

            return $item;
        });
    }

    public function updateItem(MotorDesignItem $item, array $data): MotorDesignItem
    {
        return DB::transaction(function () use ($item, $data): MotorDesignItem {
            $this->lockActive($item->design);
            $this->fillItem($item, $data);
            $item->save();

            return $item;
        });
    }

    public function removeItem(MotorDesignItem $item): void
    {
        DB::transaction(function () use ($item): void {
            $this->lockActive($item->design);
            $item->delete();
        });
    }

    private function fillItem(MotorDesignItem $item, array $data): void
    {
        $partId = filled($data['part_id'] ?? null) ? (int) $data['part_id'] : null;
        $description = filled($data['description'] ?? null) ? trim((string) $data['description']) : null;
        $notes = filled($data['notes'] ?? null) ? trim((string) $data['notes']) : null;

        if (($partId === null) === ($description === null)) {
            throw new BusinessRuleException('Bir ürün seçin ya da deneme bileşeninin adını yazın (ikisinden yalnızca biri).');
        }

        $quantity = Quantity::parse((string) ($data['quantity'] ?? ''));

        if (bccomp($quantity, '0', Quantity::SCALE) <= 0) {
            throw new BusinessRuleException('Miktar sıfırdan büyük olmalıdır.');
        }

        if ($partId !== null) {
            $part = Part::query()->with('unit')->find($partId) ?? throw new BusinessRuleException('Ürün bulunamadı.');

            if (in_array($part->type, [PartType::FinishedGood, PartType::IndirectConsumable], true)) {
                throw new BusinessRuleException("{$part->type->label()} tasarıma bileşen olarak eklenemez.");
            }

            if (! $part->is_active && $partId !== $item->getOriginal('part_id')) {
                throw new BusinessRuleException("{$part->code} {$part->name} pasif; tasarıma eklenemez.");
            }

            $duplicate = MotorDesignItem::query()->where('motor_design_id', $item->motor_design_id)->where('part_id', $partId)
                ->when($item->exists, fn ($q) => $q->whereKeyNot($item->getKey()))->exists();

            if ($duplicate) {
                throw new BusinessRuleException("{$part->code} {$part->name} bu tasarımda zaten var; miktarını değiştirin.");
            }

            $unit = $part->unit;
            $item->forceFill(['part_id' => $partId, 'description' => null, 'unit_id' => null]);
        } else {
            $unit = Unit::query()->find($data['unit_id'] ?? 0) ?? throw new BusinessRuleException('Deneme bileşeninin birimi zorunludur.');
            $item->forceFill(['part_id' => null, 'description' => mb_substr($description, 0, 150), 'unit_id' => $unit->getKey()]);
        }

        if (! $unit->allows_decimal && ! Quantity::isWhole($quantity)) {
            throw new BusinessRuleException("{$unit->name} biriminde miktar tam sayı olmalıdır.");
        }

        $item->forceFill(['quantity' => $quantity, 'notes' => $notes === null ? null : mb_substr($notes, 0, 500)]);
    }

    private function lockActive(MotorDesign $design): void
    {
        $locked = MotorDesign::query()->lockForUpdate()->findOrFail($design->getKey());

        if (! $locked->is_active) {
            throw new BusinessRuleException('Pasif tasarımın bileşenleri değiştirilemez; önce aktifleştirin.');
        }
    }

    private function ensureValid(MotorDesign $design): void
    {
        if ($design->code === '') {
            throw new BusinessRuleException('Tasarım kodu zorunludur.');
        }

        if ($design->name === '') {
            throw new BusinessRuleException('Tasarım adı zorunludur.');
        }

        $duplicate = MotorDesign::query()->where('code', $design->code)
            ->when($design->exists, fn ($q) => $q->whereKeyNot($design->getKey()))->exists();

        if ($duplicate) {
            throw new BusinessRuleException("{$design->code} kodlu bir tasarım zaten var.");
        }
    }

    private function normalize(array $data): array
    {
        if (array_key_exists('description', $data)) {
            $data['description'] = filled($data['description']) ? trim((string) $data['description']) : null;
        }

        return $data;
    }
}
