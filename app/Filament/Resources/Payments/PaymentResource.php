<?php

namespace App\Filament\Resources\Payments;

use App\Enums\CashAccountKind;
use App\Enums\ChequeDirection;
use App\Enums\ChequeStatus;
use App\Enums\Currency;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentDirection;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Filament\Resources\Payments\Pages\CreatePayment;
use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Filament\Resources\Payments\Pages\ViewPayment;
use App\Models\CashAccount;
use App\Models\Cheque;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\InvoiceService;
use App\Support\Money;
use BackedEnum;
use Closure;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class PaymentResource extends Resource
{
    protected static ?string $model = Payment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Ön muhasebe';

    protected static ?int $navigationSort = 20;

    protected static ?string $modelLabel = 'tahsilat/ödeme';

    protected static ?string $pluralModelLabel = 'tahsilat ve ödemeler';

    protected static ?string $navigationLabel = 'Tahsilat ve ödemeler';

    public static function enum(string $class, mixed $state): mixed
    {
        return $state instanceof $class ? $state : $class::tryFrom((string) $state);
    }

    public static function form(Schema $schema): Schema
    {
        $method = fn (Get $get): ?PaymentMethod => static::enum(PaymentMethod::class, $get('method'));
        $isCheque = fn (Get $get): bool => in_array($method($get), [PaymentMethod::Cheque, PaymentMethod::Note], true);

        return $schema->components([
            Section::make()->columns(3)->columnSpanFull()->schema([
                Select::make('direction')->label('İşlem')->options(PaymentDirection::class)->required()->live()
                    ->afterStateUpdated(fn (Set $set) => $set('allocations', [])),
                Select::make('customer_id')->label('Cari')->required()->searchable()->live()
                    ->options(fn (): array => Customer::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                    ->afterStateUpdated(function (?string $state, Set $set): void {
                        if ($customer = Customer::query()->find($state)) {
                            $set('currency', $customer->default_currency->value);
                            $set('account_currency', $customer->default_currency->value);
                        }
                    }),
                DatePicker::make('payment_date')->label('Tarih')->required()->default(today())->native(false)->displayFormat('d.m.Y'),
                Select::make('method')->label('Yöntem')->required()->live()
                    ->options(fn (Get $get): array => collect(PaymentMethod::cases())
                        ->reject(fn (PaymentMethod $m) => $m === PaymentMethod::Endorsement && static::enum(PaymentDirection::class, $get('direction')) !== PaymentDirection::Made)
                        ->mapWithKeys(fn (PaymentMethod $m) => [$m->value => $m->label()])->all()),
                Select::make('cash_account_id')->label('Kasa / banka hesabı')
                    ->visible(fn (Get $get): bool => in_array($method($get), [PaymentMethod::Cash, PaymentMethod::Bank], true))
                    ->required(fn (Get $get): bool => in_array($method($get), [PaymentMethod::Cash, PaymentMethod::Bank], true))
                    ->options(fn (Get $get): array => CashAccount::query()->where('is_active', true)
                        ->where('kind', $method($get) === PaymentMethod::Cash ? CashAccountKind::Cash->value : CashAccountKind::Bank->value)
                        ->when(static::enum(Currency::class, $get('currency')), fn ($q, Currency $c) => $q->where('currency', $c->value))
                        ->get()->mapWithKeys(fn (CashAccount $a) => [$a->getKey() => $a->label()])->all()),
                Select::make('cheque_id')->label('Ciro edilecek çek/senet')
                    ->visible(fn (Get $get): bool => $method($get) === PaymentMethod::Endorsement)
                    ->required(fn (Get $get): bool => $method($get) === PaymentMethod::Endorsement)
                    ->options(fn (): array => Cheque::query()->where('direction', ChequeDirection::Received->value)->where('status', ChequeStatus::Portfolio->value)
                        ->orderBy('due_date')->get()->mapWithKeys(fn (Cheque $c) => [$c->getKey() => $c->label()])->all())
                    ->helperText('Tutar ve para birimi çekten alınır.'),
                TextInput::make('amount')->label('Tutar')->inputMode('decimal')
                    ->visible(fn (Get $get): bool => $method($get) !== PaymentMethod::Endorsement)
                    ->required(fn (Get $get): bool => $method($get) !== PaymentMethod::Endorsement)
                    ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                        if (filled($value) && (! Money::isValid($value) || Money::compare(Money::parse($value), '0') <= 0)) {
                            $fail('Sıfırdan büyük geçerli bir tutar girin.');
                        }
                    }),
                Select::make('currency')->label('Ödemenin para birimi')->options(Currency::class)->default(Currency::TRY->value)->required()->live()
                    ->visible(fn (Get $get): bool => $method($get) !== PaymentMethod::Endorsement),
                Select::make('account_currency')->label('Cari para birimi')->options(Currency::class)->default(Currency::TRY->value)->live()
                    ->helperText('Farklıysa ödeme tarihindeki kurla çevrilir.'),
                TextInput::make('exchange_rate')->label('Çevrim kuru (1 ödeme birimi = ? cari birimi)')->inputMode('decimal')
                    ->visible(fn (Get $get): bool => filled($get('account_currency')) && filled($get('currency'))
                        && static::enum(Currency::class, $get('account_currency')) !== static::enum(Currency::class, $get('currency')))
                    ->helperText('Boş bırakılırsa TCMB kurlarından hesaplanır.'),
            ]),
            Section::make('Çek / senet bilgileri')->columns(4)->columnSpanFull()->visible($isCheque)->schema([
                TextInput::make('cheque_number')->label('Numara')->required($isCheque)->maxLength(50),
                TextInput::make('cheque_bank')->label('Banka')->maxLength(100)->required(fn (Get $get): bool => $method($get) === PaymentMethod::Cheque),
                TextInput::make('cheque_drawer')->label('Keşideci')->maxLength(255),
                DatePicker::make('cheque_due_date')->label('Vade')->required($isCheque)->native(false)->displayFormat('d.m.Y'),
            ]),
            Section::make('Faturalara bağla (isteğe bağlı)')
                ->description('Bağlanmayan tutar vadesi en eski açık belgeleri kapatır.')
                ->columnSpanFull()
                ->collapsed()
                ->schema([
                    Repeater::make('allocations')->hiddenLabel()->defaultItems(0)->columns(2)->addActionLabel('Fatura ekle')->schema([
                        Select::make('invoice_id')->label('Fatura')->required()
                            ->options(fn (Get $get): array => static::allocatableInvoices($get('../../customer_id'), $get('../../direction'), $get('../../account_currency'))),
                        TextInput::make('amount')->label('Tutar')->required()->inputMode('decimal'),
                    ]),
                ]),
            Textarea::make('description')->label('Açıklama')->rows(2)->columnSpanFull(),
        ]);
    }

    /** @return array<int, string> */
    private static function allocatableInvoices(mixed $customerId, mixed $direction, mixed $currency): array
    {
        $direction = static::enum(PaymentDirection::class, $direction);
        $currency = static::enum(Currency::class, $currency);

        if (blank($customerId) || $direction === null || $currency === null) {
            return [];
        }

        $sign = $direction === PaymentDirection::Received ? 1 : -1;

        return Invoice::query()->where('customer_id', $customerId)->where('status', InvoiceStatus::Issued->value)->where('currency', $currency->value)
            ->orderBy('due_date')->get()
            ->filter(fn (Invoice $i) => app(InvoiceService::class)->receivableSign($i->type()) === $sign)
            ->mapWithKeys(fn (Invoice $i) => [$i->getKey() => "{$i->displayNumber()} — vade {$i->due_date->format('d.m.Y')} — ".Money::format((string) $i->grand_total, $i->currency->value)])
            ->all();
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('İptal edildi')->icon('heroicon-o-x-circle')->iconColor('danger')->columnSpanFull()
                ->visible(fn (Payment $record): bool => $record->status === PaymentStatus::Cancelled)
                ->schema([TextEntry::make('cancellation_reason')->label('İptal açıklaması')]),
            Section::make()->columns(4)->columnSpanFull()->schema([
                TextEntry::make('direction')->label('İşlem')->badge(),
                TextEntry::make('customer.name')->label('Cari'),
                TextEntry::make('payment_date')->label('Tarih')->date('d.m.Y'),
                TextEntry::make('status')->label('Durum')->badge(),
                TextEntry::make('method')->label('Yöntem'),
                TextEntry::make('target')->label('Hesap / çek')
                    ->state(fn (Payment $record): string => $record->cashAccount?->label() ?? $record->cheque?->label() ?? '—'),
                TextEntry::make('amount')->label('Tutar')->weight('bold')
                    ->formatStateUsing(fn ($state, Payment $record): string => Money::format($state, $record->currency->value)),
                TextEntry::make('account_amount')->label('Cari tutarı')
                    ->visible(fn (Payment $record): bool => $record->currency !== $record->account_currency)
                    ->formatStateUsing(fn ($state, Payment $record): string => Money::format($state, $record->account_currency->value).' (kur '.Money::format((string) $record->exchange_rate, scale: 6).')'),
                TextEntry::make('user.name')->label('Kaydeden'),
                TextEntry::make('description')->label('Açıklama')->placeholder('—')->columnSpanFull(),
            ]),
            Section::make('Bağlı faturalar')->columnSpanFull()
                ->visible(fn (Payment $record): bool => $record->allocatedInvoices()->exists())
                ->schema([
                    RepeatableEntry::make('allocatedInvoices')->hiddenLabel()->columns(2)->schema([
                        TextEntry::make('number')->label('Fatura')->state(fn (Invoice $record): string => $record->displayNumber()),
                        TextEntry::make('pivot.amount')->label('Bağlanan')->formatStateUsing(fn ($state): string => Money::format($state)),
                    ]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('customer'))
            ->defaultSort('payment_date', 'desc')
            ->columns([
                TextColumn::make('payment_date')->label('Tarih')->date('d.m.Y')->sortable(),
                TextColumn::make('direction')->label('İşlem')->badge(),
                TextColumn::make('customer.name')->label('Cari')->searchable(),
                TextColumn::make('method')->label('Yöntem'),
                TextColumn::make('amount')->label('Tutar')->alignEnd()
                    ->formatStateUsing(fn ($state, Payment $record): string => Money::format($state, $record->currency->value)),
                TextColumn::make('status')->label('Durum')->badge(),
            ])
            ->filters([
                SelectFilter::make('direction')->label('İşlem')->options(PaymentDirection::class),
                SelectFilter::make('method')->label('Yöntem')->options(PaymentMethod::class),
                SelectFilter::make('customer_id')->label('Cari')->relationship('customer', 'name')->searchable(),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPayments::route('/'),
            'create' => CreatePayment::route('/create'),
            'view' => ViewPayment::route('/{record}'),
        ];
    }
}
