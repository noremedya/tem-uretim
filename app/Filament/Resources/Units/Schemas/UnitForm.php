<?php

namespace App\Filament\Resources\Units\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class UnitForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Ad')
                            ->required()
                            ->maxLength(50)
                            ->unique(ignoreRecord: true)
                            ->helperText('Örn. adet, kg, metre'),
                        Toggle::make('allows_decimal')
                            ->label('Küsuratlı miktar kabul eder')
                            ->default(false)
                            ->inline(false)
                            ->helperText('Kapalıysa bu birimdeki miktarlar tam sayı olmalıdır (ör. adet).'),
                    ]),
            ]);
    }
}
