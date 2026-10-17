<?php

namespace App\Filament\Resources\Invoices\Schemas;

use App\Enums\Currency;
use App\Enums\InvoiceKind;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\Customer;
use App\Models\Invoice;
use App\Support\Money;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class InvoiceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Belge')
                ->columns(3)
                ->columnSpanFull()
                ->schema([
                    Select::make('type')
                        ->label('Belge türü')
                        ->options(InvoiceType::class)
                        ->required()
                        ->live()
                        ->disabledOn('edit')
                        ->afterStateUpdated(fn (Set $set) => $set('customer_id', null)),
                    Select::make('customer_id')
                        ->label('Cari')
                        ->required()
                        ->searchable()
                        ->live()
                        ->options(fn (Get $get): array => static::customerOptions($get('type')))
                        ->afterStateUpdated(function (?string $state, Set $set): void {
                            if ($customer = Customer::query()->find($state)) {
                                $set('currency', $customer->default_currency->value);
                            }
                        })
                        ->helperText('Satış belgelerinde müşteri, alış belgelerinde tedarikçi işaretli cariler.'),
                    TextInput::make('counterparty_number')
                        ->label('Karşı tarafın belge no')
                        ->maxLength(50)
                        ->visible(fn (Get $get): bool => ($type = static::type($get('type'))) !== null && ! $type->issuedByUs())
                        ->helperText('Alış faturası ve satış iadesi karşı tarafın kestiği belgelerdir; kesmeden önce zorunlu.'),
                    Select::make('original_invoice_id')
                        ->label('Orijinal fatura')
                        ->visible(fn (Get $get): bool => static::type($get('type'))?->kind() === InvoiceKind::Return)
                        ->options(fn (Get $get): array => static::originalOptions($get('type'), $get('customer_id')))
                        ->searchable(),
                    DatePicker::make('invoice_date')->label('Fatura tarihi')->required()->default(today())->native(false)->displayFormat('d.m.Y'),
                    DatePicker::make('due_date')
                        ->label('Vade')
                        ->native(false)
                        ->displayFormat('d.m.Y')
                        ->afterOrEqual('invoice_date')
                        ->helperText('Boş bırakılırsa fatura tarihi + carinin vade günü.'),
                    Select::make('currency')
                        ->label('Para birimi')
                        ->options(Currency::class)
                        ->default(Currency::TRY->value)
                        ->required()
                        ->live(),
                    TextInput::make('exchange_rate')
                        ->label('Kur')
                        ->inputMode('decimal')
                        ->visible(fn (Get $get): bool => static::currency($get('currency')) !== null && static::currency($get('currency')) !== Currency::TRY)
                        ->formatStateUsing(fn ($state): ?string => Money::toInput($state, Money::RATE_SCALE))
                        ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                            if (filled($value) && (! Money::isValid($value, Money::RATE_SCALE) || Money::compare(Money::parse($value, Money::RATE_SCALE), '0') <= 0)) {
                                $fail('Sıfırdan büyük geçerli bir kur girin.');
                            }
                        })
                        ->helperText('Boş bırakılırsa fatura tarihinin kuruyla dolar.'),
                    Textarea::make('notes')->label('Notlar')->rows(2)->columnSpanFull(),
                ]),
        ]);
    }

    /** Filament enum seçenekli alanın değerini enum veya metin olarak verebilir. */
    public static function type(mixed $state): ?InvoiceType
    {
        return $state instanceof InvoiceType ? $state : InvoiceType::tryFrom((string) $state);
    }

    public static function currency(mixed $state): ?Currency
    {
        return $state instanceof Currency ? $state : Currency::tryFrom((string) $state);
    }

    /** @return array<int, string> */
    private static function customerOptions(mixed $type): array
    {
        $type = static::type($type);

        if ($type === null) {
            return [];
        }

        return Customer::query()
            ->where('is_active', true)
            ->where($type->requiresCustomer() ? 'is_customer' : 'is_supplier', true)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /** @return array<int, string> */
    private static function originalOptions(mixed $type, mixed $customerId): array
    {
        $type = static::type($type);

        if ($type === null || blank($customerId)) {
            return [];
        }

        return Invoice::query()
            ->where('customer_id', $customerId)
            ->where('direction', $type->direction()->value)
            ->where('kind', InvoiceKind::Normal->value)
            ->where('status', InvoiceStatus::Issued->value)
            ->orderByDesc('invoice_date')
            ->get()
            ->mapWithKeys(fn (Invoice $i) => [$i->getKey() => "{$i->displayNumber()} — {$i->invoice_date->format('d.m.Y')} — ".Money::format((string) $i->grand_total, $i->currency->value)])
            ->all();
    }
}
