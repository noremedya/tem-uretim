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
                    // Parti no ve tarih iş emri açılırken otomatik verilir; değişmez.
                    TextInput::make('batch_number')
                        ->label('Parti no')
                        ->disabled()
                        ->dehydrated(false),
                    DatePicker::make('date')
                        ->label('Tarih')
                        ->disabled()
                        ->dehydrated(false)
                        ->native(false)
                        ->displayFormat('d.m.Y'),
                    Textarea::make('notes')->label('Notlar')->rows(3)->columnSpanFull(),
                ]),
        ]);
    }
}
