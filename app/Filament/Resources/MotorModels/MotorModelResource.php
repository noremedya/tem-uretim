<?php

namespace App\Filament\Resources\MotorModels;

use App\Filament\Resources\MotorModels\Pages\CreateMotorModel;
use App\Filament\Resources\MotorModels\Pages\EditMotorModel;
use App\Filament\Resources\MotorModels\Pages\ListMotorModels;
use App\Filament\Resources\MotorModels\Pages\ViewMotorModel;
use App\Filament\Resources\MotorModels\Schemas\MotorModelForm;
use App\Filament\Resources\MotorModels\Schemas\MotorModelInfolist;
use App\Filament\Resources\MotorModels\Tables\MotorModelsTable;
use App\Models\MotorModel;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class MotorModelResource extends Resource
{
    protected static ?string $model = MotorModel::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'Üretim';

    protected static ?int $navigationSort = 10;

    protected static ?string $modelLabel = 'motor modeli';

    protected static ?string $pluralModelLabel = 'motor modelleri';

    protected static ?string $navigationLabel = 'Motor Modelleri';

    protected static ?string $recordTitleAttribute = 'code';

    public static function form(Schema $schema): Schema
    {
        return MotorModelForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return MotorModelInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MotorModelsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMotorModels::route('/'),
            'create' => CreateMotorModel::route('/create'),
            'view' => ViewMotorModel::route('/{record}'),
            'edit' => EditMotorModel::route('/{record}/edit'),
        ];
    }
}
