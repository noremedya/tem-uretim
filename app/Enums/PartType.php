<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Stok kartı türü. Davranış türe bağlıdır; muhasebe kodu config/accounting.php'den gelir.
 */
enum PartType: string implements HasLabel
{
    case RawMaterial = 'raw_material';
    case Component = 'component';
    case DirectConsumable = 'direct_consumable';
    case IndirectConsumable = 'indirect_consumable';
    case SemiFinished = 'semi_finished';
    case FinishedGood = 'finished_good';

    public function label(): string
    {
        return match ($this) {
            self::RawMaterial => 'Hammadde',
            self::Component => 'Komponent',
            self::DirectConsumable => 'Direkt sarf',
            self::IndirectConsumable => 'Endirekt sarf',
            self::SemiFinished => 'Yarı mamul',
            self::FinishedGood => 'Mamul',
        };
    }

    public function accountingCode(): string
    {
        return (string) config("accounting.part_types.{$this->value}");
    }

    /** Arayüzde muhasebe koduyla birlikte: "150.01 – Hammadde". */
    public function getLabel(): string
    {
        return "{$this->accountingCode()} – {$this->label()}";
    }

    /**
     * Elle açılabilir / elle atanabilir mi? Mamul kartı motor modelinden oluşur (Aşama 4).
     */
    public function isManuallyAssignable(): bool
    {
        return $this !== self::FinishedGood;
    }

    /** @return array<string, string> Formda seçilebilen türler (değer => etiket). */
    public static function manualOptions(): array
    {
        return collect(self::cases())
            ->filter(fn (self $type) => $type->isManuallyAssignable())
            ->mapWithKeys(fn (self $type) => [$type->value => $type->getLabel()])
            ->all();
    }
}
