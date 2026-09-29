<?php

namespace App\Filament\Resources\MotorModels\Pages;

use App\Filament\Resources\MotorModels\MotorModelResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMotorModels extends ListRecords
{
    protected static string $resource = MotorModelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
