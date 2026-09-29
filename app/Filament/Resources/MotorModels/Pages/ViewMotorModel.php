<?php

namespace App\Filament\Resources\MotorModels\Pages;

use App\Filament\Resources\MotorModels\Actions\MotorModelStatusActions;
use App\Filament\Resources\MotorModels\MotorModelResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewMotorModel extends ViewRecord
{
    protected static string $resource = MotorModelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            MotorModelStatusActions::deactivate(),
            MotorModelStatusActions::activate(),
        ];
    }
}
