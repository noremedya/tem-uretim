<?php

namespace App\Filament\Resources\Prices;

use App\Enums\Currency;
use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\Prices\Pages\CreatePrice;
use App\Filament\Resources\Prices\Pages\EditPrice;
use App\Filament\Resources\Prices\Pages\ListPrices;
use App\Filament\Support\BusinessRuleNotification;
use App\Models\Customer;
use App\Models\MotorModel;
use App\Models\Price;
use App\Services\PriceService;
use App\Support\Money;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Satış fiyatları: motor modeli liste fiyatı ve müşteriye özel fiyat. Geçerliliği başlamış fiyat değişmez.
 */
class PriceResource extends Resource
{
    protected static ?string $model = Price::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|UnitEnum|null $navigationGroup = 'Ön muhasebe';

    protected static ?int $navigationSort = 80;

    protected static ?string $modelLabel = 'fiyat';

    protected static ?string $pluralModelLabel = 'fiyatlar';

    protected static ?string $navigationLabel = 'Fiyatlar';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    Select::make('motor_model_id')
                        ->label('Motor modeli')
                        ->options(fn (): array => MotorModel::query()->where('is_active', true)->orderBy('code')->get()
                            ->mapWithKeys(fn (MotorModel $m) => [$m->getKey() => "{$m->code} — {$m->name}"])->all())
                        ->searchable()
                        ->required()
                        ->disabledOn('edit'),
                    Select::make('customer_id')
                        ->label('Müşteri')
                        ->placeholder('Liste fiyatı (tüm müşteriler)')
                        ->options(fn (): array => Customer::query()->where('is_active', true)->where('is_customer', true)->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable()
                        ->disabledOn('edit')
                        ->helperText('Boş bırakılırsa liste fiyatı olur. Müşteriye özel fiyat liste fiyatından önce gelir.'),
                    Select::make('currency')
                        ->label('Para birimi')
                        ->options(Currency::class)
                        ->default(Currency::TRY->value)
                        ->required(),
                    TextInput::make('unit_price')
                        ->label('Birim fiyat (KDV hariç)')
                        ->required()
                        ->inputMode('decimal')
                        ->formatStateUsing(fn ($state): ?string => Money::toInput($state))
                        ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                            if (! Money::isValid($value) || Money::compare(Money::parse($value), '0') <= 0) {
                                $fail('Sıfırdan büyük geçerli bir tutar girin (ör. 12.500,00).');
                            }
                        }),
                    DatePicker::make('valid_from')
                        ->label('Geçerlilik başlangıcı')
                        ->required()
                        ->default(today())
                        ->minDate(today())
                        ->native(false)
                        ->displayFormat('d.m.Y')
                        ->helperText('Bir sonraki fiyat başlayana kadar geçerlidir. Geçmiş tarih girilemez.'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['motorModel', 'customer']))
            ->defaultSort('valid_from', 'desc')
            ->columns([
                TextColumn::make('motorModel.code')
                    ->label('Model')
                    ->description(fn (Price $record): string => $record->motorModel->name)
                    ->searchable(['code', 'name']),
                TextColumn::make('customer.name')->label('Müşteri')->placeholder('Liste fiyatı')->searchable(),
                TextColumn::make('unit_price')
                    ->label('Birim fiyat')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state, Price $record): string => Money::format($state, $record->currency->value)),
                TextColumn::make('valid_from')->label('Geçerlilik başlangıcı')->date('d.m.Y')->sortable(),
                TextColumn::make('state')
                    ->label('Durum')
                    ->badge()
                    ->state(fn (Price $record): string => $record->isFuture() ? 'İleri tarihli' : 'Başladı')
                    ->color(fn (string $state): string => $state === 'İleri tarihli' ? 'warning' : 'gray'),
            ])
            ->filters([
                SelectFilter::make('motor_model_id')->label('Model')->relationship('motorModel', 'code')->searchable()->preload(),
                SelectFilter::make('customer_id')->label('Müşteri')->relationship('customer', 'name')->searchable(),
                TernaryFilter::make('list_price')
                    ->label('Fiyat türü')
                    ->trueLabel('Liste fiyatları')
                    ->falseLabel('Müşteri fiyatları')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNull('customer_id'),
                        false: fn (Builder $query) => $query->whereNotNull('customer_id'),
                    ),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->modalDescription('Yalnızca ileri tarihli fiyat silinebilir.')
                    ->using(function (Price $record, Action $action): bool {
                        try {
                            app(PriceService::class)->delete($record);
                        } catch (BusinessRuleException $exception) {
                            BusinessRuleNotification::send($exception);
                            $action->halt();
                        }

                        return true;
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPrices::route('/'),
            'create' => CreatePrice::route('/create'),
            'edit' => EditPrice::route('/{record}/edit'),
        ];
    }
}
