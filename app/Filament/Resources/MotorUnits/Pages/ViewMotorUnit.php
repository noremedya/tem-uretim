<?php

namespace App\Filament\Resources\MotorUnits\Pages;

use App\Filament\Resources\MotorUnits\Actions\ShipMotorUnitsAction;
use App\Filament\Resources\MotorUnits\MotorUnitResource;
use App\Filament\Support\AuditTrailAction;
use Filament\Resources\Pages\ViewRecord;

class ViewMotorUnit extends ViewRecord
{
    protected static string $resource = MotorUnitResource::class;

    protected function getHeaderActions(): array
    {
        return [
            AuditTrailAction::make(),
            ShipMotorUnitsAction::single(),
        ];
    }
}
