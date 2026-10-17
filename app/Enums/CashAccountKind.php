<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum CashAccountKind: string implements HasLabel
{
    case Cash = 'cash';
    case Bank = 'bank';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Kasa',
            self::Bank => 'Banka',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
