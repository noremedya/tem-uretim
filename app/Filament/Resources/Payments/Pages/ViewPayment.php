<?php

namespace App\Filament\Resources\Payments\Pages;

use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Support\BusinessRuleNotification;
use App\Models\Payment;
use App\Services\PaymentService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

class ViewPayment extends ViewRecord
{
    protected static string $resource = PaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('cancel')
                ->label('İptal et')
                ->icon(Heroicon::OutlinedXCircle)
                ->color('danger')
                ->authorize('cancel')
                ->modalDescription('Cari ve kasa/banka hareketleri ters kaydedilir. Çeki tahsil edilmiş, ciro edilmiş veya ödenmiş kayıt iptal edilemez.')
                ->schema([Textarea::make('reason')->label('İptal açıklaması')->required()->maxLength(1000)->rows(3)])
                ->action(function (Payment $record, array $data, Action $action): void {
                    try {
                        app(PaymentService::class)->cancel($record, Auth::user(), $data['reason']);
                    } catch (BusinessRuleException $exception) {
                        BusinessRuleNotification::send($exception);
                        $action->halt();
                    }

                    $record->refresh();
                    Notification::make()->success()->title('Kayıt iptal edildi')->send();
                }),
        ];
    }
}
