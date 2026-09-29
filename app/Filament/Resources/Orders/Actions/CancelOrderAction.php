<?php

namespace App\Filament\Resources\Orders\Actions;

use App\Exceptions\BusinessRuleException;
use App\Filament\Support\BusinessRuleNotification;
use App\Models\Order;
use App\Services\OrderService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * Sipariş iptali: yalnızca açık ve kısmi siparişte, açıklama zorunlu, geri alınamaz.
 */
class CancelOrderAction
{
    public static function make(): Action
    {
        return Action::make('cancel')
            ->label('Siparişi iptal et')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->authorize('cancel')
            ->modalHeading('Siparişi iptal et')
            ->modalDescription('İptal geri alınamaz. Sipariş ve kalemleri kayıtlarda kalır.')
            ->modalSubmitActionLabel('İptal et')
            ->schema([
                Textarea::make('reason')
                    ->label('İptal açıklaması')
                    ->required()
                    ->maxLength(1000)
                    ->rows(3),
            ])
            ->action(function (Order $record, array $data, Action $action): void {
                try {
                    app(OrderService::class)->cancel($record, $data['reason']);
                } catch (BusinessRuleException $exception) {
                    BusinessRuleNotification::send($exception);
                    $action->halt();
                }

                // Sayfadaki kayıt güncel duruma getirilir (durum, iptal açıklaması, sürüm).
                $record->refresh();

                Notification::make()->success()->title('Sipariş iptal edildi')->send();
            });
    }
}
