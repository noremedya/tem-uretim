<?php

namespace App\Filament\Resources\PartSubtypes\Pages;

use App\Filament\Resources\PartSubtypes\PartSubtypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPartSubtypes extends ListRecords
{
    protected static string $resource = PartSubtypeResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Yeni alt tür')];
    }
}
