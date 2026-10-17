<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum InvoiceDirection: string implements HasLabel
{
    case Sales = 'sales';
    case Purchase = 'purchase';

    public function label(): string
    {
        return match ($this) {
            self::Sales => 'Satış',
            self::Purchase => 'Alış',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
