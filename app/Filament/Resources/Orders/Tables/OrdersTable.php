<?php

namespace App\Filament\Resources\Orders\Tables;

use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\Schemas\OrderInfolist;
use App\Models\Order;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OrdersTable
{
    public static function configure(Table $table, bool $showCustomer = true): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('order_number')
                    ->label('Sipariş no')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('customer.name')
                    ->label('Müşteri')
                    ->searchable()
                    ->sortable()
                    ->visible($showCustomer),
                TextColumn::make('customer_reference')
                    ->label('Müşteri sipariş no')
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('order_date')
                    ->label('Sipariş tarihi')
                    ->date('d.m.Y')
                    ->sortable(),
                TextColumn::make('due_date')
                    ->label('Termin')
                    ->date('d.m.Y')
                    ->sortable()
                    ->color(fn (Order $record): ?string => OrderInfolist::isOverdue($record) ? 'danger' : null)
                    ->weight(fn (Order $record): ?string => OrderInfolist::isOverdue($record) ? 'bold' : null),
                TextColumn::make('items_sum_quantity')
                    ->label('Toplam adet')
                    ->sum('items', 'quantity')
                    ->numeric()
                    ->alignEnd(),
                TextColumn::make('status')
                    ->label('Durum')
                    ->badge(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Durum')
                    ->options(OrderStatus::class)
                    ->multiple(),
                SelectFilter::make('customer_id')
                    ->label('Müşteri')
                    ->relationship('customer', 'name')
                    ->searchable()
                    ->preload()
                    ->visible($showCustomer),
                Filter::make('order_date')
                    ->label('Sipariş tarihi')
                    ->schema([
                        DatePicker::make('from')->label('Sipariş tarihi (başlangıç)')->native(false)->displayFormat('d.m.Y'),
                        DatePicker::make('until')->label('Sipariş tarihi (bitiş)')->native(false)->displayFormat('d.m.Y'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('order_date', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('order_date', '<=', $date))),
                Filter::make('overdue')
                    ->label('Yalnızca termini geçenler')
                    ->toggle()
                    ->query(fn (Builder $query) => $query
                        ->whereIn('status', [OrderStatus::Open->value, OrderStatus::Partial->value])
                        ->whereDate('due_date', '<', today())),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }
}
