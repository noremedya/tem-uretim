<?php

namespace App\Filament\Resources\Cheques;

use App\Enums\ChequeDirection;
use App\Enums\ChequeKind;
use App\Enums\ChequeStatus;
use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\Cheques\Pages\ListCheques;
use App\Filament\Support\BusinessRuleNotification;
use App\Models\CashAccount;
use App\Models\Cheque;
use App\Services\ChequeService;
use App\Support\Money;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Çek ve senetler: tahsilat/ödemeyle oluşur; portföydekiler tahsil edilir, karşılıksız işaretlenir veya ödenir.
 */
class ChequeResource extends Resource
{
    protected static ?string $model = Cheque::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static string|UnitEnum|null $navigationGroup = 'Ön muhasebe';

    protected static ?int $navigationSort = 30;

    protected static ?string $modelLabel = 'çek/senet';

    protected static ?string $pluralModelLabel = 'çek ve senetler';

    protected static ?string $navigationLabel = 'Çek ve senetler';

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('customer'))
            ->defaultSort('due_date')
            ->columns([
                TextColumn::make('due_date')->label('Vade')->date('d.m.Y')->sortable()
                    ->color(fn (Cheque $record): ?string => $record->status === ChequeStatus::Portfolio && $record->due_date->lt(today()) ? 'danger' : null),
                TextColumn::make('direction')->label('Yön')->badge()->color('gray'),
                TextColumn::make('kind')->label('Tür'),
                TextColumn::make('number')->label('No')->searchable(),
                TextColumn::make('customer.name')->label('Cari')->searchable(),
                TextColumn::make('bank_name')->label('Banka')->placeholder('—')->toggleable(),
                TextColumn::make('drawer')->label('Keşideci')->placeholder('—')->toggleable(),
                TextColumn::make('amount')->label('Tutar')->alignEnd()
                    ->formatStateUsing(fn ($state, Cheque $record): string => Money::format($state, $record->currency->value)),
                TextColumn::make('status')->label('Durum')->badge(),
            ])
            ->filters([
                SelectFilter::make('status')->label('Durum')->options(ChequeStatus::class)->default(ChequeStatus::Portfolio->value),
                SelectFilter::make('direction')->label('Yön')->options(ChequeDirection::class),
                SelectFilter::make('kind')->label('Tür')->options(ChequeKind::class),
            ])
            ->recordActions([
                Action::make('collect')
                    ->label('Tahsil et')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('success')
                    ->authorize('process')
                    ->visible(fn (Cheque $record): bool => $record->direction === ChequeDirection::Received)
                    ->schema(fn (Cheque $record): array => [static::accountSelect($record), static::dateInput()])
                    ->action(static::run(fn (Cheque $r, array $d) => app(ChequeService::class)->collect($r, (int) $d['cash_account_id'], $d['date'], Auth::user()), 'Tahsil edildi')),
                Action::make('bounce')
                    ->label('Karşılıksız')
                    ->icon('heroicon-o-exclamation-triangle')
                    ->color('danger')
                    ->authorize('process')
                    ->visible(fn (Cheque $record): bool => $record->direction === ChequeDirection::Received)
                    ->modalDescription('Cari, çek/senet tutarı kadar tekrar borçlanır.')
                    ->schema([static::dateInput(), Textarea::make('description')->label('Açıklama')->rows(2)])
                    ->action(static::run(fn (Cheque $r, array $d) => app(ChequeService::class)->bounce($r, $d['date'], Auth::user(), $d['description'] ?? null), 'Karşılıksız olarak işaretlendi')),
                Action::make('pay')
                    ->label('Ödendi')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->authorize('process')
                    ->visible(fn (Cheque $record): bool => $record->direction === ChequeDirection::Issued)
                    ->schema(fn (Cheque $record): array => [static::accountSelect($record), static::dateInput()])
                    ->action(static::run(fn (Cheque $r, array $d) => app(ChequeService::class)->pay($r, (int) $d['cash_account_id'], $d['date'], Auth::user()), 'Ödendi olarak işaretlendi')),
            ]);
    }

    private static function accountSelect(Cheque $cheque): Select
    {
        return Select::make('cash_account_id')
            ->label('Kasa / banka hesabı')
            ->required()
            ->options(CashAccount::query()->where('is_active', true)->where('currency', $cheque->currency->value)->get()
                ->mapWithKeys(fn (CashAccount $a) => [$a->getKey() => $a->label()])->all());
    }

    private static function dateInput(): DatePicker
    {
        return DatePicker::make('date')->label('Tarih')->required()->default(today())->native(false)->displayFormat('d.m.Y');
    }

    private static function run(Closure $callback, string $title): Closure
    {
        return function (Cheque $record, array $data, Action $action) use ($callback, $title): void {
            try {
                $callback($record, $data);
            } catch (BusinessRuleException $exception) {
                BusinessRuleNotification::send($exception);
                $action->halt();
            }

            Notification::make()->success()->title($title)->send();
        };
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCheques::route('/'),
        ];
    }
}
