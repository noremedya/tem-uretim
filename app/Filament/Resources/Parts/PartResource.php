<?php

namespace App\Filament\Resources\Parts;

use App\Filament\RelationManagers\BomItemsRelationManager;
use App\Filament\Resources\Parts\Pages\CreatePart;
use App\Filament\Resources\Parts\Pages\EditPart;
use App\Filament\Resources\Parts\Pages\ListParts;
use App\Filament\Resources\Parts\Pages\ViewPart;
use App\Filament\Resources\Parts\RelationManagers\StockBalancesRelationManager;
use App\Filament\Resources\Parts\RelationManagers\StockMovementsRelationManager;
use App\Filament\Resources\Parts\Schemas\PartForm;
use App\Filament\Resources\Parts\Schemas\PartInfolist;
use App\Filament\Resources\Parts\Tables\PartsTable;
use App\Models\Part;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class PartResource extends Resource
{
    protected static ?string $model = Part::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static string|UnitEnum|null $navigationGroup = 'Stok';

    protected static ?int $navigationSort = 10;

    protected static ?string $modelLabel = 'ürün';

    protected static ?string $pluralModelLabel = 'ürünler';

    protected static ?string $navigationLabel = 'Ürünler';

    protected static ?string $recordTitleAttribute = 'name';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['unit', 'stockBalances', 'brand']);
    }

    public static function form(Schema $schema): Schema
    {
        return PartForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return PartInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PartsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            StockBalancesRelationManager::class,
            BomItemsRelationManager::class,
            StockMovementsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListParts::route('/'),
            'create' => CreatePart::route('/create'),
            'view' => ViewPart::route('/{record}'),
            'edit' => EditPart::route('/{record}/edit'),
        ];
    }
}
