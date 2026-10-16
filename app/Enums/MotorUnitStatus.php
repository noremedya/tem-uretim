<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum MotorUnitStatus: string implements HasColor, HasLabel
{
    case InStock = 'in_stock';
    case Shipped = 'shipped';
    // Geri alınan iş emrinin motoru: kayıt silinmez, seri no tekrar kullanılmaz.
    case Voided = 'voided';

    public function label(): string
    {
        return match ($this) {
            self::InStock => 'Stokta',
            self::Shipped => 'Sevk edildi',
            self::Voided => 'İptal edildi',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    public function getColor(): string
    {
        return match ($this) {
            self::InStock => 'success',
            self::Shipped => 'info',
            self::Voided => 'gray',
        };
    }
}
