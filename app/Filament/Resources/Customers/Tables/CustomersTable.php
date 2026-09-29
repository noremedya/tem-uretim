<?php

namespace App\Filament\Resources\Customers\Tables;

use App\Filament\Resources\Customers\Actions\CustomerStatusActions;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

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
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                CustomerStatusActions::deactivate(),
                CustomerStatusActions::activate(),
            ]);
    }
}
