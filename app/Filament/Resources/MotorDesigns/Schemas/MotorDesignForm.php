<?php

namespace App\Filament\Resources\MotorDesigns\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class MotorDesignForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(2)
                ->columnSpanFull()
                ->description('Deneme reçetesi: stoktan düşmez, iş emri açılamaz. Bileşenleri kaydettikten sonra aşağıdan ekleyin.')
                ->schema([
                    TextInput::make('code')->label('Kod')->required()->maxLength(50),
                    TextInput::make('name')->label('Ad')->required()->maxLength(150),
                    Textarea::make('description')->label('Açıklama')->rows(3)->columnSpanFull(),
                ]),
        ]);
    }
}
