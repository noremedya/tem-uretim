<?php

namespace App\Filament\Resources\PartSubtypes;

use App\Filament\Resources\PartSubtypes\Pages\CreatePartSubtype;
use App\Filament\Resources\PartSubtypes\Pages\EditPartSubtype;
use App\Filament\Resources\PartSubtypes\Pages\ListPartSubtypes;
use App\Filament\Resources\PartSubtypes\Schemas\PartSubtypeForm;
use App\Filament\Resources\PartSubtypes\Tables\PartSubtypesTable;
use App\Models\PartSubtype;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class PartSubtypeResource extends Resource
{
    protected static ?string $model = PartSubtype::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static string|UnitEnum|null $navigationGroup = 'Stok';

    protected static ?int $navigationSort = 34;

    protected static ?string $modelLabel = 'alt tür';

    protected static ?string $pluralModelLabel = 'alt türler';

    protected static ?string $navigationLabel = 'Alt türler';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return PartSubtypeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PartSubtypesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPartSubtypes::route('/'),
            'create' => CreatePartSubtype::route('/create'),
            'edit' => EditPartSubtype::route('/{record}/edit'),
        ];
    }
}
