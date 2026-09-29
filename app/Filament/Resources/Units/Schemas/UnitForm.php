<?php

namespace App\Filament\Resources\Units\Schemas;

use App\Models\Unit;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
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
                    ->schema(static::fields()),
            ]);
    }

    /**
     * Birim alanları: birim kaynağında ve parça formundaki "+" (yeni birim) penceresinde ortak kullanılır.
     *
     * @return array<Component>
     */
    public static function fields(): array
    {
        return [
            TextInput::make('name')
                ->label('Ad')
                ->required()
                ->maxLength(50)
                ->unique(Unit::class, 'name', ignoreRecord: true)
                ->helperText('Örn. Adet, Kg, Metre'),
            Toggle::make('allows_decimal')
                ->label('Küsuratlı miktar kabul eder')
                ->default(false)
                ->inline(false)
                ->helperText('Kapalıysa bu birimdeki miktarlar tam sayı olmalıdır (ör. adet).'),
        ];
    }
}
