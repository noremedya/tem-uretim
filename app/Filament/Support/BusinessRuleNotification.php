<?php

namespace App\Filament\Support;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\StaleModelException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/**
 * İş kuralı ihlallerini kullanıcıya anlaşılır Türkçe bildirim olarak gösterir.
 */
class BusinessRuleNotification
{
    public static function send(BusinessRuleException $exception, ?string $refreshUrl = null): void
    {
        $notification = Notification::make()
            ->danger()
            ->title($exception instanceof StaleModelException ? 'Kayıt başka biri tarafından değiştirildi' : 'İşlem yapılamadı')
            ->body($exception->getMessage());

        if ($exception instanceof StaleModelException) {
            $notification->persistent();

            if ($refreshUrl !== null) {
                $notification->actions([
                    Action::make('refresh')
                        ->label('Sayfayı yenile')
                        ->button()
                        ->url($refreshUrl),
                ]);
            }
        }

        $notification->send();
    }
}
