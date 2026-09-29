<?php

namespace App\Filament\Resources\Customers\Actions;

use App\Models\Customer;
use App\Services\CustomerService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * Silme yerine pasifleştirme / aktifleştirme aksiyonları (liste, görüntüleme ve düzenleme sayfalarında ortak).
 */
class CustomerStatusActions
{
    public static function deactivate(): Action
    {
        return Action::make('deactivate')
            ->label('Pasifleştir')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->authorize('deactivate')
            ->requiresConfirmation()
            ->modalHeading('Müşteriyi pasifleştir')
            ->modalDescription('Pasif müşteriye yeni sipariş açılamaz. Mevcut siparişleri görünür kalır.')
            ->action(function (Customer $record): void {
                app(CustomerService::class)->deactivate($record);

                Notification::make()->success()->title('Müşteri pasifleştirildi')->send();
            });
    }

    public static function activate(): Action
    {
        return Action::make('activate')
            ->label('Aktifleştir')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->authorize('activate')
            ->requiresConfirmation()
            ->modalHeading('Müşteriyi aktifleştir')
            ->action(function (Customer $record): void {
                app(CustomerService::class)->activate($record);

                Notification::make()->success()->title('Müşteri aktifleştirildi')->send();
            });
    }
}
