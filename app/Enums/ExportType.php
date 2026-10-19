<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Satış faturasının ihracat türü (CLAUDE.md bölüm 5 "İhracat"). */
enum ExportType: string implements HasLabel
{
    /** Yurt dışındaki alıcıya doğrudan ihracat: KDV %0. */
    case Export = 'export';

    /** İhracatçıya ihraç kaydıyla teslim: KDV hesaplanır, tahsil edilmez (tecil). */
    case RegisteredExport = 'registered_export';

    public function label(): string
    {
        return match ($this) {
            self::Export => 'İhracat (KDV 11/1-a)',
            self::RegisteredExport => 'İhraç kayıtlı satış (KDV 11/1-c)',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    /** e-Fatura KDV istisna/muafiyet kodu. */
    public function exemptionCode(): string
    {
        return match ($this) {
            self::Export => '301',
            self::RegisteredExport => '701',
        };
    }

    /** Faturaya yazılan istisna açıklaması. */
    public function exemptionText(): string
    {
        return match ($this) {
            self::Export => '3065 sayılı KDV Kanunu 11/1-a maddesi uyarınca mal ihracatı; KDV istisnası.',
            self::RegisteredExport => '3065 sayılı KDV Kanunu 11/1-c maddesi uyarınca ihraç kaydıyla teslim edildiğinden KDV tahsil edilmemiştir.',
        };
    }
}
