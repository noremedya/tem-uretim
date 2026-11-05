<?php

namespace App\Filament\Resources\MotorDesigns\Pages;

use App\Filament\Resources\MotorDesigns\MotorDesignResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMotorDesigns extends ListRecords
{
    protected static string $resource = MotorDesignResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Yeni tasarım')];
    }
}
