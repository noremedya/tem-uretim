<?php

namespace App\Filament\Resources\MotorDesigns\Pages;

use App\Filament\Pages\DesignBuilder;
use App\Filament\Resources\MotorDesigns\MotorDesignResource;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListMotorDesigns extends ListRecords
{
    protected static string $resource = MotorDesignResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                Action::make('builder')->label('Bileşen seçerek tasarla')->icon(Heroicon::OutlinedWrenchScrewdriver)
                    ->url(DesignBuilder::getUrl())->visible(fn (): bool => DesignBuilder::canAccess()),
                CreateAction::make()->label('Boş tasarım (elle satır ekleyerek)'),
            ])->label('Yeni tasarım')->icon(Heroicon::OutlinedPlus)->button(),
        ];
    }
}
