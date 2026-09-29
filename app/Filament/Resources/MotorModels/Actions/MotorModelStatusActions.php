<?php

namespace App\Filament\Resources\MotorModels\Actions;

use App\Models\MotorModel;
use App\Services\MotorModelService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * Silme yerine pasifleştirme / aktifleştirme aksiyonları (liste, görüntüleme ve düzenleme sayfalarında ortak).
 */
class MotorModelStatusActions
{
    public static function deactivate(): Action
    {
        return Action::make('deactivate')
            ->label('Pasifleştir')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->authorize('deactivate')
            ->requiresConfirmation()
            ->modalHeading('Motor modelini pasifleştir')
            ->modalDescription('Pasif model yeni sipariş kalemlerine eklenemez. Mevcut kayıtlar korunur.')
            ->action(function (MotorModel $record): void {
                app(MotorModelService::class)->deactivate($record);

                Notification::make()->success()->title('Motor modeli pasifleştirildi')->send();
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
            ->modalHeading('Motor modelini aktifleştir')
            ->action(function (MotorModel $record): void {
                app(MotorModelService::class)->activate($record);

                Notification::make()->success()->title('Motor modeli aktifleştirildi')->send();
            });
    }
}
