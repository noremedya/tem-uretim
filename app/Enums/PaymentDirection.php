<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum PaymentDirection: string implements HasColor, HasLabel
{
    case Received = 'received';
    case Made = 'made';

    public function label(): string
    {
        return match ($this) {
            self::Received => 'Tahsilat',
            self::Made => 'Ödeme',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Received => 'success',
            self::Made => 'warning',
        };
    }
}
