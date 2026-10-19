<?php

namespace App\Filament\Resources\Invoices\Schemas;

use App\Enums\Currency;
use App\Enums\ExportType;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Support\Money;
use App\Support\Quantity;
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
            Section::make('İhracat')
                ->icon('heroicon-o-globe-alt')
                ->columns(4)
                ->columnSpanFull()
                ->visible(fn (Invoice $record): bool => $record->export_type !== null)
                ->schema([
                    TextEntry::make('export_type')->label('İhracat türü')->badge()->color('info'),
                    TextEntry::make('exemption')->label('KDV istisnası')->columnSpan(3)
                        ->state(fn (Invoice $record): string => "{$record->export_type->exemptionCode()} — {$record->export_type->exemptionText()}"),
                    TextEntry::make('incoterm')->label('Teslim şekli')->placeholder('—')
                        ->visible(fn (Invoice $record): bool => $record->export_type === ExportType::Export),
                    TextEntry::make('transport_mode')->label('Gönderim şekli')->placeholder('—')
                        ->visible(fn (Invoice $record): bool => $record->export_type === ExportType::Export),
                    TextEntry::make('packages')->label('Kap')->placeholder('—')
                        ->visible(fn (Invoice $record): bool => $record->export_type === ExportType::Export)
                        ->state(fn (Invoice $record): ?string => $record->package_count ? "{$record->package_count} {$record->package_type?->label()}" : null),
                    TextEntry::make('weights')->label('Brüt / net ağırlık')->placeholder('—')
                        ->visible(fn (Invoice $record): bool => $record->export_type === ExportType::Export)
                        ->state(fn (Invoice $record): ?string => $record->gross_weight_kg || $record->net_weight_kg
                            ? Quantity::format((string) $record->gross_weight_kg, 'kg').' / '.Quantity::format((string) $record->net_weight_kg, 'kg')
                            : null)
                        ->helperText(fn (Invoice $record): ?string => $record->isDraft() ? 'Net ağırlık boşsa kesimde motor modellerinden hesaplanır.' : null),
                    TextEntry::make('customs_declaration_number')->label('Gümrük beyannamesi')
                        ->state(fn (Invoice $record): string => $record->customs_declaration_number
                            ? "{$record->customs_declaration_number} ({$record->customs_declaration_date->format('d.m.Y')})"
                            : 'Bekleniyor')
                        ->color(fn (Invoice $record): ?string => $record->customs_declaration_number ? null : 'warning')
                        ->columnSpan(2),
                    TextEntry::make('export_deadline')->label('İhracat için son gün')
                        ->visible(fn (Invoice $record): bool => $record->export_type === ExportType::RegisteredExport)
                        ->state(fn (Invoice $record): ?string => $record->exportDeadline()?->format('d.m.Y'))
                        ->color(fn (Invoice $record): ?string => $record->customs_declaration_number === null && $record->exportDeadline()?->lt(today()) ? 'danger' : null)
                        ->helperText(fn (Invoice $record): ?string => $record->customs_declaration_number === null && $record->exportDeadline()?->lt(today())
                            ? 'Süre geçti: ihracatçının beyannamesi gelmediyse tecil edilen KDV tahsil edilmelidir.'
                            : 'Bu tarihe kadar ihracatçının gümrük beyannamesi işlenmelidir.'),
                ]),
            Section::make('Toplamlar')
                ->columns(5)
                ->columnSpanFull()
                ->schema([
                    $money('subtotal', 'Ara toplam (brüt)'),
                    $money('discount_total', 'İskonto'),
                    $money('vat_total', 'KDV'),
                    $money('deferred_vat_total', 'Tecil edilen KDV')
                        ->visible(fn (Invoice $record): bool => $record->export_type === ExportType::RegisteredExport)
                        ->helperText('Faturada gösterilir, tahsil edilmez; cariye KDV hariç tutar yazılır.'),
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
