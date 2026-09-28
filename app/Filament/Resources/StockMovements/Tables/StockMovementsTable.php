<?php

namespace App\Filament\Resources\StockMovements\Tables;

use App\Enums\StockMovementType;
use App\Filament\Resources\StockMovements\Actions\CorrectStockMovementAction;
use App\Models\StockMovement;
use App\Support\Quantity;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Stok hareketleri tablosu: hareket listesi ve parça sayfasındaki geçmiş ortak kullanır.
 */
class StockMovementsTable
{
    public static function configure(Table $table, bool $showPart = true): Table
    {
        $unit = fn (StockMovement $record): string => $record->part->unit->name;

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['part.unit', 'user', 'correctedMovement'])
                ->withExists('correction'))
            ->defaultSort(fn (Builder $query) => $query->orderByDesc('created_at')->orderByDesc('id'))
            ->columns([
                TextColumn::make('created_at')
                    ->label('Tarih')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
                TextColumn::make('part.code')
                    ->label('Parça')
                    ->description(fn (StockMovement $record): string => $record->part->name)
                    ->searchable(['code', 'name'])
                    ->visible($showPart),
                TextColumn::make('type')
                    ->label('Tür')
                    ->badge()
                    ->description(fn (StockMovement $record): ?string => match (true) {
                        $record->type === StockMovementType::Correction => "#{$record->corrected_movement_id} hareketinin ters kaydı",
                        (bool) $record->correction_exists => 'Ters kaydedildi',
                        default => null,
                    }),
                TextColumn::make('quantity')
                    ->label('Miktar')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state, StockMovement $record): string => Quantity::format($state, $unit($record), signed: true))
                    ->color(fn ($state): string => Quantity::compare((string) $state, '0') > 0 ? 'success' : 'danger'),
                TextColumn::make('balance_before')
                    ->label('Önceki bakiye')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state, StockMovement $record): string => Quantity::format($state, $unit($record)))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('balance_after')
                    ->label('Sonraki bakiye')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state, StockMovement $record): string => Quantity::format($state, $unit($record))),
                TextColumn::make('description')
                    ->label('Açıklama')
                    ->wrap()
                    ->limit(80)
                    ->placeholder('—'),
                TextColumn::make('user.name')
                    ->label('Kullanıcı'),
                TextColumn::make('id')
                    ->label('No')
                    ->prefix('#')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('part_id')
                    ->label('Parça')
                    ->relationship('part', 'code')
                    ->getOptionLabelFromRecordUsing(fn ($record): string => "{$record->code} — {$record->name}")
                    ->searchable(['code', 'name'])
                    ->visible($showPart),
                SelectFilter::make('type')
                    ->label('Tür')
                    ->options(StockMovementType::class)
                    ->multiple(),
                SelectFilter::make('user_id')
                    ->label('Kullanıcı')
                    ->relationship('user', 'name')
                    ->searchable(),
                Filter::make('created_at')
                    ->label('Tarih aralığı')
                    ->schema([
                        DatePicker::make('from')->label('Başlangıç'),
                        DatePicker::make('until')->label('Bitiş'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->where('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->where('created_at', '<', Carbon::parse($date)->addDay()))),
            ])
            ->recordActions([
                CorrectStockMovementAction::make(),
            ]);
    }
}
