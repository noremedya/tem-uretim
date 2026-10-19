<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Kap cinsi. code(): e-Fatura'daki UN/ECE Rec. 21 ambalaj kodu. */
enum PackageType: string implements HasLabel
{
    case Pallet = 'pallet';
    case Carton = 'carton';
    case Crate = 'crate';
    case Box = 'box';

    public function label(): string
    {
        return match ($this) {
            self::Pallet => 'Palet',
            self::Carton => 'Koli',
            self::Crate => 'Sandık',
            self::Box => 'Kutu',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    public function code(): string
    {
        return match ($this) {
            self::Pallet => 'PX',
            self::Carton => 'CT',
            self::Crate => 'CR',
            self::Box => 'BX',
        };
    }
}
