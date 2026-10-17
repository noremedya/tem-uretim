<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum InvoiceKind: string implements HasLabel
{
    case Normal = 'normal';
    case Return = 'return';

    public function label(): string
    {
        return match ($this) {
            self::Normal => 'Fatura',
            self::Return => 'İade',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
