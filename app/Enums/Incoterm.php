<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Teslim şekli (Incoterms 2020). Değer, e-Fatura'daki koddur. */
enum Incoterm: string implements HasLabel
{
    case EXW = 'EXW';
    case FCA = 'FCA';
    case FAS = 'FAS';
    case FOB = 'FOB';
    case CFR = 'CFR';
    case CIF = 'CIF';
    case CPT = 'CPT';
    case CIP = 'CIP';
    case DAP = 'DAP';
    case DPU = 'DPU';
    case DDP = 'DDP';

    public function label(): string
    {
        return $this->value.' — '.match ($this) {
            self::EXW => 'İş yerinde teslim',
            self::FCA => 'Taşıyıcıya teslim',
            self::FAS => 'Gemi doğrultusunda teslim',
            self::FOB => 'Gemide teslim',
            self::CFR => 'Masraflar ve navlun ödenmiş',
            self::CIF => 'Masraflar, sigorta ve navlun ödenmiş',
            self::CPT => 'Taşıma ücreti ödenmiş',
            self::CIP => 'Taşıma ve sigorta ödenmiş',
            self::DAP => 'Belirlenen yerde teslim',
            self::DPU => 'Belirlenen yerde boşaltılmış teslim',
            self::DDP => 'Gümrük vergileri ödenmiş teslim',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
