<?php

namespace App\Filament\Resources\PartSubtypes\Schemas;

use App\Enums\PartType;
use App\Models\PartSubtype;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PartSubtypeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([Section::make()->columns(2)->schema(static::fields())]);
    }

    /**
     * Alt tür alanları: alt tür kaynağında ve ürün formundaki "+" penceresinde ortak (orada ana tür üründen gelir).
     *
     * @return array<Component>
     */
    public static function fields(bool $withType = true): array
    {
        return array_values(array_filter([
            $withType ? Select::make('type')
                ->label('Ana tür')
                ->options(collect(PartType::cases())->mapWithKeys(fn (PartType $t) => [$t->value => $t->getLabel()])->all())
                ->required()
                ->disabled(fn (?PartSubtype $record): bool => $record?->parts()->exists() ?? false)
                ->helperText(fn (?PartSubtype $record): ?string => ($record?->parts()->exists() ?? false) ? 'Ürünlerde kullanılan alt türün ana türü değiştirilemez.' : null) : null,
            TextInput::make('name')->label('Ad')->required()->maxLength(100)->helperText('Örn. Silisli sac, Rulman, Bobinajlı stator'),
        ]));
    }
}
