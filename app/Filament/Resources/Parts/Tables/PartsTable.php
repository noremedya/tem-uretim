<?php

namespace App\Filament\Resources\Parts\Tables;

use App\Enums\PartType;
use App\Filament\Resources\Parts\Actions\PartStatusActions;
use App\Models\Part;
use App\Models\PartSubtype;
use App\Models\Warehouse;
use App\Support\Quantity;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PartsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('code')
            ->columns([
                ImageColumn::make('image')
                    ->label('')
                    ->state(fn (Part $record): ?string => $record->imageUrl())
                    ->imageHeight(36)
                    ->square()
                    ->toggleable(),
                TextColumn::make('code')
                    ->label('Kod')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Ad')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Part $record): ?string => $record->description ? str($record->description)->limit(60)->toString() : null),
                TextColumn::make('brand.name')
                    ->label('Marka')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('type')
                    ->label('Tür')
                    ->badge()
                    ->description(fn (Part $record): ?string => $record->subtype?->name),
                // Depo filtresi seçiliyse o depodaki bakiye, değilse tüm depoların toplamı.
                TextColumn::make('balance')
                    ->label(fn ($livewire): string => static::warehouseFilter($livewire) !== null ? 'Bakiye (depo)' : 'Bakiye')
                    ->alignEnd()
                    ->state(fn (Part $record, $livewire): string => ($warehouseId = static::warehouseFilter($livewire)) !== null
                        ? $record->quantityIn($warehouseId)
                        : $record->totalStock())
                    ->formatStateUsing(fn ($state, Part $record): string => Quantity::format($state, $record->unit->name))
                    ->color(fn (Part $record): ?string => $record->isCritical() ? 'danger' : null)
                    ->weight(fn (Part $record): ?string => $record->isCritical() ? 'bold' : null)
                    ->icon(fn (Part $record) => $record->isCritical() ? 'heroicon-m-exclamation-triangle' : null)
                    ->tooltip(fn (Part $record): ?string => $record->isCritical() ? 'En az bir depoda kritik seviyede' : null),
                TextColumn::make('critical_level')
                    ->label('Kritik seviye')
                    ->alignEnd()
                    ->state(fn (Part $record): string => $record->criticalLevel())
                    ->formatStateUsing(fn ($state, Part $record): string => Quantity::compare((string) $state, '0') > 0
                        ? Quantity::format($state, $record->unit->name)
                        : '—')
                    ->tooltip('Varsayılan depodaki kritik seviye')
                    ->toggleable(),
                TextColumn::make('barcode')
                    ->label('Barkod')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Durum')
                    ->trueLabel('Aktif')
                    ->falseLabel('Pasif')
                    ->placeholder('Tümü')
                    ->default(true),
                SelectFilter::make('type')
                    ->label('Tür')
                    ->options(PartType::class),
                SelectFilter::make('subtype_id')
                    ->label('Alt tür')
                    ->options(fn (): array => PartSubtype::query()->get()->sortBy(fn (PartSubtype $s) => $s->fullLabel())
                        ->mapWithKeys(fn (PartSubtype $s) => [$s->getKey() => $s->fullLabel()])->all())
                    ->searchable()
                    ->multiple(),
                SelectFilter::make('brand_id')
                    ->label('Marka')
                    ->relationship('brand', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('warehouse')
                    ->label('Depo')
                    ->options(fn () => Warehouse::query()->orderBy('code')->pluck('name', 'id'))
                    ->query(fn (Builder $query, array $data) => $query->when(
                        filled($data['value'] ?? null),
                        fn (Builder $q) => $q->whereHas('stockBalances', fn (Builder $b) => $b->where('warehouse_id', $data['value'])->where('quantity', '<>', 0)),
                    )),
                SelectFilter::make('unit_id')
                    ->label('Birim')
                    ->relationship('unit', 'name'),
                Filter::make('critical')
                    ->label('Yalnızca kritik stoktakiler')
                    ->toggle()
                    ->query(fn (Builder $query) => $query->critical()),
                Filter::make('idle')
                    ->label(fn (): string => sprintf('Yalnızca hareketsizler (%d+ gün)', config('stock.idle_days')))
                    ->toggle()
                    ->query(fn (Builder $query) => $query->idle()),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                PartStatusActions::deactivate(),
                PartStatusActions::activate(),
            ]);
    }

    /** Seçili depo filtresi (yoksa null). */
    private static function warehouseFilter($livewire): ?int
    {
        $value = $livewire?->tableFilters['warehouse']['value'] ?? null;

        return filled($value) ? (int) $value : null;
    }
}
