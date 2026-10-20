<?php

namespace App\Filament\Widgets;

use App\Enums\Permission;
use App\Filament\Resources\Parts\PartResource;
use App\Models\Part;
use App\Support\Quantity;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Facades\Auth;

/** Kritik stoktaki parçalar (yönetici, depo): bakiye ≤ kritik seviye. */
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
            ->query(fn () => Part::query()->with(['unit', 'stockBalance'])->where('is_active', true)->critical()->orderBy('code'))
            ->defaultPaginationPageOption(5)
            ->recordUrl(fn (Part $record): string => PartResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('code')->label('Kod')->weight('bold'),
                TextColumn::make('name')->label('Ad'),
                TextColumn::make('stockBalance.quantity')->label('Bakiye')->alignEnd()->color('danger')
                    ->formatStateUsing(fn ($state, Part $record): string => Quantity::format($state, $record->unit->name)),
                TextColumn::make('critical_level')->label('Kritik seviye')->alignEnd()
                    ->formatStateUsing(fn ($state, Part $record): string => Quantity::format($state, $record->unit->name)),
            ])
            ->emptyStateHeading('Kritik seviyede ürün yok');
    }
}
