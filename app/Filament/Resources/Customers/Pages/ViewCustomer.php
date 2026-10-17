<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Enums\Currency;
use App\Enums\Permission;
use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\Customers\Actions\CustomerStatusActions;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Support\BusinessRuleNotification;
use App\Models\Customer;
use App\Models\Order;
use App\Services\LedgerService;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

class ViewCustomer extends ViewRecord
{
    protected static string $resource = CustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('newOrder')
                ->label('Yeni sipariş')
                ->icon(Heroicon::OutlinedPlus)
                // Pasif müşteriye sipariş açılamaz.
                ->visible(fn (Customer $record): bool => $record->is_active && Auth::user()->can('create', Order::class))
                ->url(fn (Customer $record): string => OrderResource::getUrl('create', ['customer' => $record->getKey()])),
            EditAction::make(),
            Action::make('opening')
                ->label('Açılış bakiyesi')
                ->icon('heroicon-o-flag')
                ->color('gray')
                ->visible(fn (): bool => Auth::user()->can(Permission::FinanceManage))
                ->modalDescription('Mikro\'daki güncel bakiye; cari ve para birimi başına tek seferlik.')
                ->schema([
                    Select::make('currency')->label('Para birimi')->options(Currency::class)->required()->default(Currency::TRY->value),
                    Radio::make('side')->label('Yön')->required()->default('debit')
                        ->options(['debit' => 'Cari borçlu (alacağımız)', 'credit' => 'Cari alacaklı (borcumuz)']),
                    TextInput::make('amount')->label('Tutar')->required()->inputMode('decimal'),
                    DatePicker::make('date')->label('Tarih')->required()->default(today())->native(false)->displayFormat('d.m.Y'),
                ])
                ->action(function (Customer $record, array $data, Action $action): void {
                    try {
                        $amount = Money::parse($data['amount']);
                        app(LedgerService::class)->openCustomerBalance(
                            $record,
                            $data['currency'] instanceof Currency ? $data['currency'] : Currency::from($data['currency']),
                            $data['side'] === 'credit' ? bcmul($amount, '-1', 2) : $amount,
                            $data['date'],
                            Auth::user(),
                        );
                    } catch (BusinessRuleException $exception) {
                        BusinessRuleNotification::send($exception);
                        $action->halt();
                    }

                    Notification::make()->success()->title('Açılış bakiyesi girildi')->send();
                }),
            CustomerStatusActions::deactivate(),
            CustomerStatusActions::activate(),
        ];
    }
}
