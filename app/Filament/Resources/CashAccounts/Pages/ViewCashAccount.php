<?php

namespace App\Filament\Resources\CashAccounts\Pages;

use App\Enums\CashMovementType;
use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\CashAccounts\CashAccountResource;
use App\Filament\Support\AuditTrailAction;
use App\Filament\Support\BusinessRuleNotification;
use App\Models\CashAccount;
use App\Services\LedgerService;
use App\Support\Money;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Auth;

class ViewCashAccount extends ViewRecord
{
    protected static string $resource = CashAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            AuditTrailAction::make(),
            EditAction::make(),
            Action::make('opening')
                ->label('Açılış bakiyesi')
                ->icon('heroicon-o-flag')
                ->color('gray')
                ->authorize('update')
                ->visible(fn (CashAccount $record): bool => ! $record->movements()->where('type', CashMovementType::Opening->value)->exists())
                ->modalDescription('Hesap başına tek seferlik; sistem öncesindeki bakiye.')
                ->schema([
                    TextInput::make('amount')->label('Bakiye')->required()->inputMode('decimal')
                        ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                            if (! Money::isValid($value)) {
                                $fail('Geçerli bir tutar girin.');
                            }
                        }),
                    DatePicker::make('date')->label('Tarih')->required()->default(today())->native(false)->displayFormat('d.m.Y'),
                ])
                ->action(function (CashAccount $record, array $data, Action $action): void {
                    try {
                        app(LedgerService::class)->openCashBalance($record, $data['amount'], $data['date'], Auth::user());
                    } catch (BusinessRuleException $exception) {
                        BusinessRuleNotification::send($exception);
                        $action->halt();
                    }

                    Notification::make()->success()->title('Açılış bakiyesi girildi')->send();
                }),
        ];
    }
}
