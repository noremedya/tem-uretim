<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ChequeDirection: string implements HasLabel
{
    case Received = 'received';
    case Issued = 'issued';

    public function label(): string
    {
        return match ($this) {
            self::Received => 'Alınan',
            self::Issued => 'Verilen',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
