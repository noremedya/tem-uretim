<?php

namespace App\Filament\Resources\MotorUnits\Pages;

use App\Filament\Resources\MotorUnits\Actions\MotorStockActions;
use App\Filament\Resources\MotorUnits\MotorUnitResource;
use Filament\Resources\Pages\ListRecords;

class ListMotorUnits extends ListRecords
{
    protected static string $resource = MotorUnitResource::class;

    protected function getHeaderActions(): array
    {
        return [MotorStockActions::registerOpening()];
    }
}
