<?php

namespace App\Filament\Resources\Customers\Tables;

use App\Filament\Resources\Customers\Actions\CustomerStatusActions;
use App\Models\Customer;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CustomersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Ad / ünvan')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('kind')
                    ->label('Tür')
                    ->badge()
                    ->state(fn (Customer $record): array => array_values(array_filter([
                        $record->is_customer ? 'Müşteri' : null,
                        $record->is_supplier ? 'Tedarikçi' : null,
                    ])))
                    ->color(fn (string $state): string => $state === 'Tedarikçi' ? 'warning' : 'info'),
                TextColumn::make('tax_number')
                    ->label('Vergi no')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('phone')
                    ->label('Telefon')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('email')
                    ->label('E-posta')
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('orders_count')
                    ->label('Sipariş')
                    ->counts('orders')
                    ->alignEnd()
                    ->sortable(),
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
                SelectFilter::make('kind')
                    ->label('Tür')
                    ->options(['customer' => 'Müşteriler', 'supplier' => 'Tedarikçiler'])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'customer' => $query->where('is_customer', true),
                        'supplier' => $query->where('is_supplier', true),
                        default => $query,
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                CustomerStatusActions::deactivate(),
                CustomerStatusActions::activate(),
            ]);
    }
}
