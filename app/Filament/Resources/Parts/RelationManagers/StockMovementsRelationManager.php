<?php

namespace App\Filament\Resources\Parts\RelationManagers;

use App\Enums\Permission;
use App\Filament\Resources\StockMovements\Tables\StockMovementsTable;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Parçanın hareket geçmişi (salt okunur). Yönetici ve depo görür.
 */
class StockMovementsRelationManager extends RelationManager
{
    protected static string $relationship = 'stockMovements';

    protected static ?string $title = 'Stok hareketleri';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can(Permission::StockMovementsView) ?? false;
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return StockMovementsTable::configure($table, showPart: false);
    }
}
