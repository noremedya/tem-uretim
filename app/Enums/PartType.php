<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum PartType: string implements HasLabel
{
    case RawMaterial = 'raw_material';
    case Component = 'component';
    case Consumable = 'consumable';

    public function label(): string
    {
        return match ($this) {
            self::RawMaterial => 'Hammadde',
            self::Component => 'Parça',
            self::Consumable => 'Sarf',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
