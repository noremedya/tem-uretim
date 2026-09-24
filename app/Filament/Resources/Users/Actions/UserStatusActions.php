<?php

namespace App\Filament\Resources\Users\Actions;

use App\Exceptions\BusinessRuleException;
use App\Filament\Support\BusinessRuleNotification;
use App\Models\User;
use App\Services\UserService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * Silme yerine pasifleştirme / aktifleştirme aksiyonları (liste ve düzenleme sayfasında ortak).
 */
class UserStatusActions
{
    public static function deactivate(): Action
    {
        return Action::make('deactivate')
            ->label('Pasifleştir')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->authorize('deactivate')
            ->requiresConfirmation()
            ->modalHeading('Kullanıcıyı pasifleştir')
            ->modalDescription('Pasif kullanıcı sisteme giriş yapamaz. Kayıtları ve geçmişi korunur.')
            ->action(function (User $record, Action $action): void {
                try {
                    app(UserService::class)->deactivate($record, Auth::user());
                } catch (BusinessRuleException $exception) {
                    BusinessRuleNotification::send($exception);
                    $action->halt();
                }

                Notification::make()->success()->title('Kullanıcı pasifleştirildi')->send();
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
            ->modalHeading('Kullanıcıyı aktifleştir')
            ->action(function (User $record, Action $action): void {
                try {
                    app(UserService::class)->activate($record);
                } catch (BusinessRuleException $exception) {
                    BusinessRuleNotification::send($exception);
                    $action->halt();
                }

                Notification::make()->success()->title('Kullanıcı aktifleştirildi')->send();
            });
    }
}
