<?php

namespace App\Filament\Resources\ExchangeRates\Pages;

use App\Filament\Resources\ExchangeRates\ExchangeRateResource;
use App\Models\ExchangeRate;
use App\Services\ExchangeRateService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListExchangeRates extends ListRecords
{
    protected static string $resource = ExchangeRateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('fetch')
                ->label("TCMB'den çek")
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->authorize('create', ExchangeRate::class)
                ->action(function (): void {
                    $saved = app(ExchangeRateService::class)->fetchFromTcmb();

                    match (true) {
                        $saved === null => Notification::make()->warning()->title('TCMB kurlarına ulaşılamadı')
                            ->body('İnternet bağlantısını kontrol edin veya kuru elle girin.')->send(),
                        $saved === [] => Notification::make()->info()->title('Bu tarihin kurları zaten kayıtlı')->send(),
                        default => Notification::make()->success()->title(count($saved).' kur kaydedildi')->send(),
                    };
                }),
            CreateAction::make()->label('Kuru elle gir'),
        ];
    }
}
