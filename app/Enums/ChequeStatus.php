<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ChequeStatus: string implements HasColor, HasLabel
{
    case Portfolio = 'portfolio';
    case Collected = 'collected';
    case Endorsed = 'endorsed';
    case Bounced = 'bounced';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Portfolio => 'Portföyde',
            self::Collected => 'Tahsil edildi',
            self::Endorsed => 'Ciro edildi',
            self::Bounced => 'Karşılıksız',
            self::Paid => 'Ödendi',
            self::Cancelled => 'İptal',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Portfolio => 'info',
            self::Collected => 'success',
            self::Endorsed => 'gray',
            self::Bounced => 'danger',
            self::Paid => 'success',
            self::Cancelled => 'gray',
        };
    }
}
