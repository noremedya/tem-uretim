<?php

namespace App\Filament\Resources\Parts\Tables;

use App\Enums\PartType;
use App\Filament\Resources\Parts\Actions\PartStatusActions;
use App\Models\Part;
use App\Support\Quantity;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
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
                TextColumn::make('code')
                    ->label('Kod')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Ad')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type')
                    ->label('Tür')
                    ->badge(),
                TextColumn::make('stockBalance.quantity')
                    ->label('Bakiye')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state, Part $record): string => Quantity::format($state, $record->unit->name))
                    ->color(fn (Part $record): ?string => $record->isCriticalAt((string) $record->stockBalance?->quantity) ? 'danger' : null)
                    ->weight(fn (Part $record): ?string => $record->isCriticalAt((string) $record->stockBalance?->quantity) ? 'bold' : null)
                    ->icon(fn (Part $record) => $record->isCriticalAt((string) $record->stockBalance?->quantity) ? 'heroicon-m-exclamation-triangle' : null),
                TextColumn::make('critical_level')
                    ->label('Kritik seviye')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state, Part $record): string => $record->tracksCriticalLevel()
                        ? Quantity::format($state, $record->unit->name)
                        : '—')
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
                SelectFilter::make('unit_id')
                    ->label('Birim')
                    ->relationship('unit', 'name'),
                Filter::make('critical')
                    ->label('Yalnızca kritik stoktakiler')
                    ->toggle()
                    ->query(fn (Builder $query) => $query->critical()),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                PartStatusActions::deactivate(),
                PartStatusActions::activate(),
            ]);
    }
}
