<?php

namespace App\Filament\Resources\MotorUnits\Pages;

use App\Filament\Resources\MotorUnits\Actions\MotorStockActions;
use App\Filament\Resources\MotorUnits\Actions\ShipMotorUnitsAction;
use App\Filament\Resources\MotorUnits\Actions\TransferMotorUnitsAction;
use App\Filament\Resources\MotorUnits\MotorUnitResource;
use App\Filament\Support\AuditTrailAction;
use App\Filament\Support\LabelActions;
use Filament\Resources\Pages\ViewRecord;

class ViewMotorUnit extends ViewRecord
{
    protected static string $resource = MotorUnitResource::class;

    protected function getHeaderActions(): array
    {
        return [
            AuditTrailAction::make(),
            LabelActions::motor(),
            ShipMotorUnitsAction::single(),
            TransferMotorUnitsAction::single(),
            MotorStockActions::receiveReturn(),
            MotorStockActions::cancelOpening(),
        ];
    }
}
