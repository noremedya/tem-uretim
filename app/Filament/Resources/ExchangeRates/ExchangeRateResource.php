<?php

namespace App\Filament\Resources\ExchangeRates;

use App\Enums\Currency;
use App\Filament\Resources\ExchangeRates\Pages\CreateExchangeRate;
use App\Filament\Resources\ExchangeRates\Pages\EditExchangeRate;
use App\Filament\Resources\ExchangeRates\Pages\ListExchangeRates;
use App\Models\ExchangeRate;
use App\Support\Money;
use BackedEnum;
use Closure;
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
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Döviz kurları: TCMB'den otomatik çekilir veya elle girilir/düzeltilir (yönetici, muhasebe).
 */
class ExchangeRateResource extends Resource
{
    protected static ?string $model = ExchangeRate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyDollar;

    protected static string|UnitEnum|null $navigationGroup = 'Ön muhasebe';

    protected static ?int $navigationSort = 90;

    protected static ?string $modelLabel = 'döviz kuru';

    protected static ?string $pluralModelLabel = 'döviz kurları';

    protected static ?string $navigationLabel = 'Döviz kurları';

    public static function form(Schema $schema): Schema
    {
        $rate = fn (string $field, string $label) => TextInput::make($field)
            ->label($label)
            ->required()
            ->inputMode('decimal')
            ->formatStateUsing(fn ($state): ?string => Money::toInput($state, Money::RATE_SCALE))
            ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                if (! Money::isValid($value, Money::RATE_SCALE) || Money::compare(Money::parse($value, Money::RATE_SCALE), '0') <= 0) {
                    $fail('Sıfırdan büyük geçerli bir kur girin (ör. 36,1234; en fazla 6 ondalık).');
                }
            });

        return $schema->components([
            Section::make()
                ->columns(4)
                ->columnSpanFull()
                ->schema([
                    Select::make('currency')
                        ->label('Para birimi')
                        ->options(collect(Currency::foreign())->mapWithKeys(fn (Currency $c) => [$c->value => $c->label()]))
                        ->required(),
                    DatePicker::make('rate_date')->label('Tarih')->required()->default(today())->native(false)->displayFormat('d.m.Y'),
                    $rate('forex_buying', 'Döviz alış'),
                    $rate('forex_selling', 'Döviz satış'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('user'))
            ->defaultSort('rate_date', 'desc')
            ->columns([
                TextColumn::make('rate_date')->label('Tarih')->date('d.m.Y')->sortable(),
                TextColumn::make('currency')->label('Para birimi'),
                TextColumn::make('forex_buying')->label('Döviz alış')->alignEnd()
                    ->formatStateUsing(fn ($state): string => Money::format($state, scale: 4)),
                TextColumn::make('forex_selling')->label('Döviz satış')->alignEnd()
                    ->formatStateUsing(fn ($state): string => Money::format($state, scale: 4)),
                TextColumn::make('source')->label('Kaynak')->badge(),
                TextColumn::make('user.name')->label('Giren')->placeholder('—')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('currency')->label('Para birimi')
                    ->options(collect(Currency::foreign())->mapWithKeys(fn (Currency $c) => [$c->value => $c->value])),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExchangeRates::route('/'),
            'create' => CreateExchangeRate::route('/create'),
            'edit' => EditExchangeRate::route('/{record}/edit'),
        ];
    }
}
