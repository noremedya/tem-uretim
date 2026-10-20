<?php

namespace App\Filament\Resources\Brands\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class BrandForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([Section::make()->schema(static::fields())]);
    }

    /**
     * Marka alanları: marka kaynağında ve ürün formundaki "+" (yeni marka) penceresinde ortak kullanılır.
     *
     * @return array<Component>
     */
    public static function fields(): array
    {
        return [
            TextInput::make('name')->label('Ad')->required()->maxLength(100)->helperText('Örn. SKF, ZVL, FAG'),
        ];
    }
}
