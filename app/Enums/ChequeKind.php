<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ChequeKind: string implements HasLabel
{
    case Cheque = 'cheque';
    case Note = 'note';

    public function label(): string
    {
        return match ($this) {
            self::Cheque => 'Çek',
            self::Note => 'Senet',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
