<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum CashMovementType: string implements HasLabel
{
    case Opening = 'opening';
    case Payment = 'payment';
    case ChequeCollected = 'cheque_collected';
    case ChequePaid = 'cheque_paid';
    case Correction = 'correction';

    public function label(): string
    {
        return match ($this) {
            self::Opening => 'Açılış',
            self::Payment => 'Tahsilat/ödeme',
            self::ChequeCollected => 'Çek tahsilatı',
            self::ChequePaid => 'Çek ödemesi',
            self::Correction => 'Ters kayıt',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
