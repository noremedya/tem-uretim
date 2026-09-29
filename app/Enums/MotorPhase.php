<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum MotorPhase: string implements HasLabel
{
    case SinglePhase = 'single_phase';
    case ThreePhase = 'three_phase';

    public function label(): string
    {
        return match ($this) {
            self::SinglePhase => 'Monofaze',
            self::ThreePhase => 'Trifaze',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
