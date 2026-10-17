<?php

namespace App\Filament\Resources\Invoices\Schemas;

use App\Enums\Currency;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Support\Money;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class InvoiceInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $money = fn (string $field, string $label) => TextEntry::make($field)
            ->label($label)
            ->formatStateUsing(fn ($state, Invoice $record): string => Money::format($state, $record->currency->value));

        return $schema->components([
            Section::make('İptal edildi')
                ->icon('heroicon-o-x-circle')
                ->iconColor('danger')
                ->columnSpanFull()
                ->visible(fn (Invoice $record): bool => $record->status === InvoiceStatus::Cancelled)
                ->schema([
                    TextEntry::make('cancellation_reason')->label('İptal açıklaması'),
                    TextEntry::make('cancelled_at')->label('İptal zamanı')->dateTime('d.m.Y H:i'),
                ]),
            Section::make()
                ->columns(4)
                ->columnSpanFull()
                ->schema([
                    TextEntry::make('number')->label('Belge no')->state(fn (Invoice $record): string => $record->displayNumber())->weight('bold')->copyable(),
                    TextEntry::make('type')->label('Tür')->state(fn (Invoice $record): string => $record->type()->label()),
                    TextEntry::make('status')->label('Durum')->badge(),
                    TextEntry::make('customer.name')->label('Cari'),
                    TextEntry::make('invoice_date')->label('Fatura tarihi')->date('d.m.Y'),
                    TextEntry::make('due_date')->label('Vade')->date('d.m.Y'),
                    TextEntry::make('currency')->label('Para birimi'),
                    TextEntry::make('exchange_rate')
                        ->label('Kur')
                        ->visible(fn (Invoice $record): bool => $record->currency !== Currency::TRY)
                        ->formatStateUsing(fn ($state): string => Money::format($state, scale: 4)),
                    TextEntry::make('originalInvoice.invoice_number')->label('Orijinal fatura')->placeholder('—')
                        ->state(fn (Invoice $record): ?string => $record->originalInvoice?->displayNumber()),
                    TextEntry::make('issuedBy.name')->label('Kesen')->placeholder('—'),
                    TextEntry::make('issued_at')->label('Kesim zamanı')->dateTime('d.m.Y H:i')->placeholder('—'),
                    TextEntry::make('notes')->label('Notlar')->placeholder('—')->columnSpanFull(),
                ]),
            Section::make('Toplamlar')
                ->columns(5)
                ->columnSpanFull()
                ->schema([
                    $money('subtotal', 'Ara toplam (brüt)'),
                    $money('discount_total', 'İskonto'),
                    $money('vat_total', 'KDV'),
                    $money('grand_total', 'Genel toplam')->weight('bold'),
                    TextEntry::make('grand_total_try')
                        ->label('TL karşılığı')
                        ->visible(fn (Invoice $record): bool => $record->currency !== Currency::TRY)
                        ->state(fn (Invoice $record): string => Money::format(
                            $record->grand_total_try ?? Money::mul((string) $record->grand_total, (string) $record->exchange_rate),
                            'TRY',
                        ))
                        ->helperText(fn (Invoice $record): ?string => $record->isDraft() ? 'Kesimde sabitlenir.' : null),
                ]),
        ]);
    }
}
