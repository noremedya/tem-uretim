<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ExchangeRateSource: string implements HasLabel
{
    case Tcmb = 'tcmb';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Tcmb => 'TCMB',
            self::Manual => 'Elle girildi',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
