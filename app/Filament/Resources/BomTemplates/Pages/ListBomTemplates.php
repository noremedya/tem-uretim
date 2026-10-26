<?php

namespace App\Filament\Resources\BomTemplates\Pages;

use App\Filament\Resources\BomTemplates\BomTemplateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBomTemplates extends ListRecords
{
    protected static string $resource = BomTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Yeni şablon')];
    }
}
