<?php

namespace App\Filament\Resources\Orders\Schemas;

use App\Enums\OrderStatus;
use App\Filament\Resources\Customers\CustomerResource;
use App\Models\Order;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Number;

class OrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('İptal edildi')
                    ->icon('heroicon-o-x-circle')
                    ->iconColor('danger')
                    ->columnSpanFull()
                    ->visible(fn (Order $record): bool => $record->status === OrderStatus::Cancelled)
                    ->schema([
                        TextEntry::make('cancellation_reason')->label('İptal açıklaması'),
                    ]),
                Section::make()
                    ->columns(4)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('order_number')->label('Sipariş no')->weight('bold')->copyable(),
                        TextEntry::make('status')->label('Durum')->badge(),
                        TextEntry::make('customer.name')
                            ->label('Müşteri')
                            ->url(fn (Order $record): ?string => Auth::user()->can('view', $record->customer)
                                ? CustomerResource::getUrl('view', ['record' => $record->customer])
                                : null),
                        TextEntry::make('customer_reference')->label('Müşteri sipariş no')->placeholder('—'),
                        TextEntry::make('order_date')->label('Sipariş tarihi')->date('d.m.Y'),
                        TextEntry::make('due_date')
                            ->label('Termin')
                            ->date('d.m.Y')
                            ->color(fn (Order $record): ?string => static::isOverdue($record) ? 'danger' : null)
                            ->helperText(fn (Order $record): ?string => static::isOverdue($record) ? 'Termin geçti' : null),
                        TextEntry::make('items_total')
                            ->label('Toplam adet')
                            ->state(fn (Order $record): string => Number::format($record->items->sum('quantity'))),
                        TextEntry::make('notes')->label('Notlar')->placeholder('—')->columnSpanFull(),
                    ]),
                Section::make('Kalemler')
                    ->columnSpanFull()
                    ->schema([
                        RepeatableEntry::make('items')
                            ->hiddenLabel()
                            ->table([
                                TableColumn::make('Model kodu'),
                                TableColumn::make('Model adı'),
                                TableColumn::make('Adet')->alignEnd(),
                            ])
                            ->schema([
                                TextEntry::make('motorModel.code'),
                                TextEntry::make('motorModel.name'),
                                TextEntry::make('quantity')->numeric()->alignEnd(),
                            ]),
                    ]),
            ]);
    }

    public static function isOverdue(Order $record): bool
    {
        return $record->status->isEditable() && $record->due_date->lt(today());
    }
}
