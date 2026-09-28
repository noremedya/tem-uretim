<?php

namespace App\Filament\Resources\Parts\Actions;

use App\Models\Part;
use App\Services\PartService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * Silme yerine pasifleştirme / aktifleştirme aksiyonları (liste, görüntüleme ve düzenleme sayfalarında ortak).
 */
class PartStatusActions
{
    public static function deactivate(): Action
    {
        return Action::make('deactivate')
            ->label('Pasifleştir')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->authorize('deactivate')
            ->requiresConfirmation()
            ->modalHeading('Parçayı pasifleştir')
            ->modalDescription('Pasif parçaya yalnızca sayım yapılabilir. Kayıtları ve hareket geçmişi korunur.')
            ->action(function (Part $record): void {
                app(PartService::class)->deactivate($record);

                Notification::make()->success()->title('Parça pasifleştirildi')->send();
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
            ->modalHeading('Parçayı aktifleştir')
            ->action(function (Part $record): void {
                app(PartService::class)->activate($record);

                Notification::make()->success()->title('Parça aktifleştirildi')->send();
            });
    }
}
