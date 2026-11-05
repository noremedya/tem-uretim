<?php

namespace App\Filament\Resources\ProductionBatches;

use App\Filament\Resources\ProductionBatches\Pages\EditProductionBatch;
use App\Filament\Resources\ProductionBatches\Pages\ListProductionBatches;
use App\Filament\Resources\ProductionBatches\Schemas\ProductionBatchForm;
use App\Filament\Resources\ProductionBatches\Tables\ProductionBatchesTable;
use App\Models\ProductionBatch;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ProductionBatchResource extends Resource
{
    protected static ?string $model = ProductionBatch::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Üretim';

    protected static ?int $navigationSort = 30;

    protected static ?string $modelLabel = 'üretim partisi';

    protected static ?string $pluralModelLabel = 'üretim partileri';

    protected static ?string $navigationLabel = 'Üretim partileri';

    protected static ?string $recordTitleAttribute = 'batch_number';

    public static function form(Schema $schema): Schema
    {
        return ProductionBatchForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductionBatchesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProductionBatches::route('/'),
            'edit' => EditProductionBatch::route('/{record}/edit'),
        ];
    }
}
