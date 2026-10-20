<?php

namespace App\Filament\Widgets;

use App\Enums\Permission;
use App\Filament\Resources\Parts\PartResource;
use App\Models\Part;
use App\Support\Quantity;
use Carbon\CarbonImmutable;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Facades\Auth;

/** Hareketsiz stok (yönetici, depo): config('stock.idle_days') gündür hareket görmeyen bakiyeli aktif parçalar. */
class IdleStock extends TableWidget
{
    protected static ?int $sort = 4;

    protected ?string $pollingInterval = '60s';

    public static function canView(): bool
    {
        return Auth::user()?->can(Permission::StockMove) ?? false;
    }

    protected function getTableHeading(): string
    {
        return sprintf('Hareketsiz stok (%d+ gün)', config('stock.idle_days'));
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => Part::query()->with(['unit', 'stockBalances'])->where('is_active', true)->idle()
                ->withMax('stockMovements as last_movement_at', 'created_at')->orderBy('last_movement_at'))
            ->defaultPaginationPageOption(5)
            ->recordUrl(fn (Part $record): string => PartResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('code')->label('Kod')->weight('bold'),
                TextColumn::make('name')->label('Ad'),
                TextColumn::make('balance')->label('Bakiye')->alignEnd()
                    ->state(fn (Part $record): string => Quantity::format($record->totalStock(), $record->unit->name)),
                TextColumn::make('last_movement_at')->label('Son hareket')
                    ->formatStateUsing(fn ($state): string => CarbonImmutable::parse($state)->format('d.m.Y')),
            ])
            ->emptyStateHeading('Hareketsiz ürün yok');
    }
}
