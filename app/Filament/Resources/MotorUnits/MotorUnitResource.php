<?php

namespace App\Filament\Resources\MotorUnits;

use App\Filament\Resources\MotorUnits\Pages\ListMotorUnits;
use App\Filament\Resources\MotorUnits\Pages\ViewMotorUnit;
use App\Filament\Resources\MotorUnits\Schemas\MotorUnitInfolist;
use App\Filament\Resources\MotorUnits\Tables\MotorUnitsTable;
use App\Models\MotorUnit;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Üretilen motorlar (salt okunur; kayıtlar iş emri tamamlanınca oluşur). Sevk buradan yapılır.
 */
class MotorUnitResource extends Resource
{
    protected static ?string $model = MotorUnit::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'Üretim';

    protected static ?int $navigationSort = 20;

    protected static ?string $modelLabel = 'motor';

    protected static ?string $pluralModelLabel = 'üretilen motorlar';

    protected static ?string $navigationLabel = 'Üretilen motorlar';

    protected static ?string $recordTitleAttribute = 'serial_number';

    public static function infolist(Schema $schema): Schema
    {
        return MotorUnitInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MotorUnitsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMotorUnits::route('/'),
            'view' => ViewMotorUnit::route('/{record}'),
        ];
    }
}
