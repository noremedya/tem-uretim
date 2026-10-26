<?php

namespace App\Filament\Resources\MotorModels\Pages;

use App\Filament\Pages\MotorBuilder;
use App\Filament\Resources\MotorModels\MotorModelResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListMotorModels extends ListRecords
{
    protected static string $resource = MotorModelResource::class;

    protected function getHeaderActions(): array
    {
        // İlk seçenek: bileşen seçerek motor oluşturma (model, mamul kartı ve reçete birlikte).
        return [
            Action::make('build')
                ->label('Bileşen seçerek oluştur')
                ->icon(Heroicon::OutlinedWrenchScrewdriver)
                ->url(MotorBuilder::getUrl())
                ->visible(fn (): bool => MotorBuilder::canAccess()),
            CreateAction::make()->label('Boş model oluştur')->color('gray'),
        ];
    }
}
