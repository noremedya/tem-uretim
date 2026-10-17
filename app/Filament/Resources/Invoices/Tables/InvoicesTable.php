<?php

namespace App\Filament\Resources\Invoices\Tables;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Support\Money;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class InvoicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('customer'))
            ->defaultSort('invoice_date', 'desc')
            ->columns([
                TextColumn::make('number')
                    ->label('Belge no')
                    ->state(fn (Invoice $record): string => $record->displayNumber())
                    ->weight('bold')
                    ->searchable(query: fn (Builder $query, string $search) => $query->where(fn (Builder $q) => $q
                        ->where('invoice_number', 'ilike', "%{$search}%")
                        ->orWhere('counterparty_number', 'ilike', "%{$search}%"))),
                TextColumn::make('type')->label('Tür')->state(fn (Invoice $record): string => $record->type()->label())->badge()->color('gray'),
                TextColumn::make('customer.name')->label('Cari')->searchable(),
                TextColumn::make('invoice_date')->label('Tarih')->date('d.m.Y')->sortable(),
                TextColumn::make('due_date')->label('Vade')->date('d.m.Y')->sortable()->toggleable(),
                TextColumn::make('grand_total')
                    ->label('Toplam')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state, Invoice $record): string => Money::format($state, $record->currency->value)),
                TextColumn::make('status')->label('Durum')->badge(),
            ])
            ->filters([
                SelectFilter::make('status')->label('Durum')->options(InvoiceStatus::class),
                SelectFilter::make('customer_id')->label('Cari')->relationship('customer', 'name')->searchable(),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
