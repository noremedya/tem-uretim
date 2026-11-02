<?php

namespace App\Filament\Resources\Warehouses\Tables;

use App\Enums\PartType;
use App\Exceptions\BusinessRuleException;
use App\Filament\Support\BusinessRuleNotification;
use App\Models\Warehouse;
use App\Services\WarehouseService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class WarehousesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('code')
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount(['stockBalances as stocked_parts_count' => fn (Builder $b) => $b->where('quantity', '<>', 0)]))
            ->columns([
                TextColumn::make('code')->label('Kod')->searchable()->sortable()->weight('bold'),
                TextColumn::make('name')->label('Ad')->searchable()->sortable(),
                TextColumn::make('default_part_types')
                    ->label('Varsayılan türler')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => PartType::tryFrom((string) $state)?->label() ?? (string) $state)
                    ->placeholder('—'),
                IconColumn::make('allows_negative')->label('Eksiye düşebilir')->boolean(),
                IconColumn::make('is_production')->label('Üretim')->boolean(),
                IconColumn::make('is_shipping')->label('Sevkiyat')->boolean(),
                TextColumn::make('stocked_parts_count')->label('Stoklu ürün')->numeric()->sortable(),
                IconColumn::make('is_active')->label('Aktif')->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Durum')->trueLabel('Aktif')->falseLabel('Pasif')->default(true),
                TernaryFilter::make('part_id')->label('Ürün depoları')->nullable()->trueLabel('Yalnızca ürün depoları')->falseLabel('Genel depolar')->placeholder('Tümü'),
            ])
            ->recordActions([
                EditAction::make(),
                static::status('deactivate', 'Pasifleştir', 'danger', fn (Warehouse $w) => app(WarehouseService::class)->deactivate($w), 'Depo pasifleştirildi'),
                static::status('activate', 'Aktifleştir', 'success', fn (Warehouse $w) => app(WarehouseService::class)->activate($w), 'Depo aktifleştirildi'),
            ]);
    }

    private static function status(string $name, string $label, string $color, \Closure $run, string $done): Action
    {
        return Action::make($name)
            ->label($label)
            ->color($color)
            ->authorize($name)
            ->requiresConfirmation()
            ->modalDescription($name === 'deactivate' ? 'Pasif depoya giriş yapılmaz; yalnızca sayım ve başka depoya transfer yapılabilir. Stoğu olan depo pasifleştirilemez.' : null)
            ->action(function (Warehouse $record, Action $action) use ($run, $done): void {
                try {
                    $run($record);
                } catch (BusinessRuleException $e) {
                    BusinessRuleNotification::send($e);
                    $action->halt();
                }
                Notification::make()->success()->title($done)->send();
            });
    }
}
