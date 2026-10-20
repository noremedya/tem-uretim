<?php

namespace App\Filament\Widgets;

use App\Enums\Permission;
use App\Filament\Resources\Parts\PartResource;
use App\Models\StockBalance;
use App\Support\Quantity;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/** Kritik stoktaki ürünler, depo bazlı (yönetici, depo): bakiye ≤ kritik seviye. */
class CriticalStock extends TableWidget
{
    protected static ?int $sort = 3;

    protected static ?string $heading = 'Kritik stok';

    protected ?string $pollingInterval = '60s';

    public static function canView(): bool
    {
        return Auth::user()?->can(Permission::StockMove) ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => StockBalance::query()->criticalOnly()
                ->with(['part.unit', 'warehouse'])
                ->whereHas('part', fn (Builder $part) => $part->where('is_active', true))
                ->join('parts', 'parts.id', '=', 'stock_balances.part_id')
                ->select('stock_balances.*')
                ->orderBy('parts.code'))
            ->defaultPaginationPageOption(5)
            ->recordUrl(fn (StockBalance $record): string => PartResource::getUrl('view', ['record' => $record->part_id]))
            ->columns([
                TextColumn::make('part.code')->label('Kod')->weight('bold'),
                TextColumn::make('part.name')->label('Ad'),
                TextColumn::make('warehouse.name')->label('Depo'),
                TextColumn::make('quantity')->label('Bakiye')->alignEnd()->color('danger')
                    ->formatStateUsing(fn ($state, StockBalance $record): string => Quantity::format($state, $record->part->unit->name)),
                TextColumn::make('critical_level')->label('Kritik seviye')->alignEnd()
                    ->formatStateUsing(fn ($state, StockBalance $record): string => Quantity::format($state, $record->part->unit->name)),
            ])
            ->emptyStateHeading('Kritik seviyede ürün yok');
    }
}
