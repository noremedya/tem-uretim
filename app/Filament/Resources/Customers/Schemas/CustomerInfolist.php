<?php

namespace App\Filament\Resources\Customers\Schemas;

use App\Enums\Currency;
use App\Enums\Permission;
use App\Models\Customer;
use App\Services\LedgerService;
use App\Services\StatementService;
use App\Support\Country;
use App\Support\Money;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class CustomerInfolist
{
    /** @return list<string> */
    private static function balanceLines(Customer $customer): array
    {
        return collect(app(LedgerService::class)->balances($customer))
            ->map(fn (string $balance, string $currency): string => match (Money::compare($balance, '0')) {
                1 => Money::format($balance, $currency).' — cari borçlu (alacağımız)',
                -1 => Money::format(ltrim($balance, '-'), $currency).' — cari alacaklı (borcumuz)',
                default => Money::format('0', $currency).' — kapalı',
            })
            ->values()
            ->all();
    }

    /** @return list<string> */
    private static function openItemLines(Customer $customer): array
    {
        return collect(array_keys(app(LedgerService::class)->balances($customer)))
            ->flatMap(fn (string $currency) => app(StatementService::class)->openItems($customer, Currency::from($currency)))
            ->map(fn (array $item): string => sprintf(
                '%s — vade %s — açık %s%s',
                $item['transaction']->description ?? $item['transaction']->type->label(),
                $item['transaction']->due_date?->format('d.m.Y') ?? '—',
                Money::format($item['open'], $item['transaction']->currency->value),
                $item['overdue'] ? ' — VADESİ GEÇTİ' : '',
            ))
            ->all();
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Bakiye ve açık belgeler')
                    ->columnSpanFull()
                    ->columns(2)
                    ->visible(fn (): bool => Auth::user()?->can(Permission::FinanceManage) ?? false)
                    ->schema([
                        TextEntry::make('balances')
                            ->label('Bakiye')
                            ->listWithLineBreaks()
                            ->state(fn (Customer $record): array => static::balanceLines($record))
                            ->placeholder('Hareket yok'),
                        TextEntry::make('open_items')
                            ->label('Açık belgeler (önce elle bağlananlar, sonra vadesi en eski)')
                            ->listWithLineBreaks()
                            ->state(fn (Customer $record): array => static::openItemLines($record))
                            ->placeholder('Açık belge yok'),
                    ]),
                Section::make()
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('name')->label('Ad / ünvan')->columnSpan(2),
                        IconEntry::make('is_active')->label('Aktif')->boolean(),
                        TextEntry::make('country_code')->label('Ülke')->formatStateUsing(fn (?string $state): ?string => Country::name($state)),
                        TextEntry::make('foreign_tax_id')->label('Yabancı vergi no')->placeholder('—')->visible(fn (Customer $record): bool => ! $record->isDomestic()),
                        TextEntry::make('tax_number')->label('Vergi no / TCKN')->placeholder('—'),
                        TextEntry::make('tax_office')->label('Vergi dairesi')->placeholder('—'),
                        TextEntry::make('phone')->label('Telefon')->placeholder('—'),
                        TextEntry::make('kind')
                            ->label('Tür')
                            ->badge()
                            ->state(fn ($record): array => array_values(array_filter([
                                $record->is_customer ? 'Müşteri' : null,
                                $record->is_supplier ? 'Tedarikçi' : null,
                            ]))),
                        TextEntry::make('payment_term_days')->label('Vade')->suffix(' gün'),
                        TextEntry::make('default_currency')->label('Varsayılan para birimi'),
                        TextEntry::make('email')->label('E-posta')->placeholder('—'),
                        TextEntry::make('address')->label('Adres')->placeholder('—')->columnSpan(2),
                    ]),
            ]);
    }
}
