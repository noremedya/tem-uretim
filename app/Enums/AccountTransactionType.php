<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum AccountTransactionType: string implements HasLabel
{
    case Opening = 'opening';
    case Invoice = 'invoice';
    case Payment = 'payment';
    case ChequeBounced = 'cheque_bounced';
    case Correction = 'correction';

    public function label(): string
    {
        return match ($this) {
            self::Opening => 'Açılış (devir)',
            self::Invoice => 'Fatura',
            self::Payment => 'Tahsilat/ödeme',
            self::ChequeBounced => 'Karşılıksız çek/senet',
            self::Correction => 'Ters kayıt',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
