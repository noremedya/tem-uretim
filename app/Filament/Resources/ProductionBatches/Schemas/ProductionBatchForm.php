<?php

namespace App\Filament\Resources\ProductionBatches\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProductionBatchForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(2)
                ->schema([
                    TextInput::make('batch_number')
                        ->label('Parti no')
                        ->required()
                        ->maxLength(50)
                        ->unique(ignoreRecord: true),
                    DatePicker::make('date')
                        ->label('Tarih')
                        ->required()
                        ->default(today())
                        ->native(false)
                        ->displayFormat('d.m.Y'),
                    Textarea::make('notes')->label('Notlar')->rows(3)->columnSpanFull(),
                ]),
        ]);
    }
}
