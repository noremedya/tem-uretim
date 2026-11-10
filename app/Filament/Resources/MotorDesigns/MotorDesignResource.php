<?php

namespace App\Filament\Resources\MotorDesigns;

use App\Filament\Resources\MotorDesigns\Pages\CreateMotorDesign;
use App\Filament\Resources\MotorDesigns\Pages\EditMotorDesign;
use App\Filament\Resources\MotorDesigns\Pages\ListMotorDesigns;
use App\Filament\Resources\MotorDesigns\Pages\ViewMotorDesign;
use App\Filament\Resources\MotorDesigns\RelationManagers\ItemsRelationManager;
use App\Filament\Resources\MotorDesigns\Schemas\MotorDesignForm;
use App\Filament\Resources\MotorDesigns\Schemas\MotorDesignInfolist;
use App\Filament\Resources\MotorDesigns\Tables\MotorDesignsTable;
use App\Models\MotorDesign;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/** Tasarımlar (deneme reçetesi): stoktan düşmeyen, iş emri açılamayan taslak motor tasarımları. */
class MotorDesignResource extends Resource
{
    protected static ?string $model = MotorDesign::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBeaker;

    protected static string|UnitEnum|null $navigationGroup = 'Üretim';

    protected static ?int $navigationSort = 28;

    protected static ?string $modelLabel = 'tasarım';

    protected static ?string $pluralModelLabel = 'tasarımlar';

    protected static ?string $navigationLabel = 'Tasarımlar';

    protected static ?string $recordTitleAttribute = 'name';

    /** Modül gizliyse menüde görünmez, sayfaları kimse açamaz (config/features.php). */
    public static function canAccess(): bool
    {
        return (bool) config('features.motor_designs') && parent::canAccess();
    }

    public static function form(Schema $schema): Schema
    {
        return MotorDesignForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return MotorDesignInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MotorDesignsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [ItemsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMotorDesigns::route('/'),
            'create' => CreateMotorDesign::route('/create'),
            'view' => ViewMotorDesign::route('/{record}'),
            'edit' => EditMotorDesign::route('/{record}/edit'),
        ];
    }
}
