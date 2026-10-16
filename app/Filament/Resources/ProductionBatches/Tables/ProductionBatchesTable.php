<?php

namespace App\Filament\Resources\ProductionBatches\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ProductionBatchesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('date', 'desc')
            ->columns([
                TextColumn::make('batch_number')->label('Parti no')->searchable()->sortable(),
                TextColumn::make('date')->label('Tarih')->date('d.m.Y')->sortable(),
                TextColumn::make('work_orders_count')->label('İş emri')->counts('workOrders')->alignEnd(),
                TextColumn::make('notes')->label('Notlar')->limit(60)->toggleable(),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
