<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum PaymentMethod: string implements HasLabel
{
    case Cash = 'cash';
    case Bank = 'bank';
    case Cheque = 'cheque';
    case Note = 'note';
    case Endorsement = 'endorsement';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Nakit',
            self::Bank => 'Banka (havale/EFT)',
            self::Cheque => 'Çek',
            self::Note => 'Senet',
            self::Endorsement => 'Ciro (portföydeki çek)',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
