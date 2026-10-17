<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum Currency: string implements HasLabel
{
    case TRY = 'TRY';
    case EUR = 'EUR';
    case USD = 'USD';

    public function label(): string
    {
        return match ($this) {
            self::TRY => 'Türk lirası (TRY)',
            self::EUR => 'Euro (EUR)',
            self::USD => 'ABD doları (USD)',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    public function symbol(): string
    {
        return match ($this) {
            self::TRY => '₺',
            self::EUR => '€',
            self::USD => '$',
        };
    }

    /** Kuru takip edilen (TRY dışındaki) para birimleri. */
    public static function foreign(): array
    {
        return [self::EUR, self::USD];
    }
}
