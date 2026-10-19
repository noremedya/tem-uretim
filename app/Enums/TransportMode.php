<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Gönderim şekli. code(): e-Fatura'daki UN/ECE Rec. 19 taşıma modu kodu. */
enum TransportMode: string implements HasLabel
{
    case Sea = 'sea';
    case Rail = 'rail';
    case Road = 'road';
    case Air = 'air';
    case Post = 'post';
    case Multimodal = 'multimodal';

    public function label(): string
    {
        return match ($this) {
            self::Sea => 'Denizyolu',
            self::Rail => 'Demiryolu',
            self::Road => 'Karayolu',
            self::Air => 'Havayolu',
            self::Post => 'Posta',
            self::Multimodal => 'Çok modlu',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    public function code(): string
    {
        return match ($this) {
            self::Sea => '1',
            self::Rail => '2',
            self::Road => '3',
            self::Air => '4',
            self::Post => '5',
            self::Multimodal => '6',
        };
    }
}
