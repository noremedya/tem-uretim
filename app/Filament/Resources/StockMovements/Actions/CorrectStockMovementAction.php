<?php

namespace App\Filament\Resources\StockMovements\Actions;

use App\Exceptions\BusinessRuleException;
use App\Filament\Support\BusinessRuleNotification;
use App\Models\StockMovement;
use App\Services\StockService;
use App\Support\Quantity;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * Hareketin ters kaydı (hata düzeltme). Kurallar StockService::correct() ve veritabanında zorlanır.
 */
class CorrectStockMovementAction
{
    public static function make(): Action
    {
        return Action::make('correct')
            ->label('Ters kayıt')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('gray')
            ->authorize('correct')
            ->hidden(fn (StockMovement $record): bool => (bool) ($record->correction_exists ?? $record->correction()->exists()))
            ->modalHeading('Ters kayıt oluştur')
            ->modalDescription(fn (StockMovement $record): string => sprintf(
                '#%d numaralı %s hareketi (%s %s: %s) tam ters miktarla (%s) düzeltilecek. Bu işlem geri alınamaz.',
                $record->id,
                mb_strtolower($record->type->label()),
                $record->part->code,
                $record->part->name,
                Quantity::format($record->quantity, $record->part->unit->name, signed: true),
                Quantity::format(Quantity::negate((string) $record->quantity), $record->part->unit->name, signed: true),
            ))
            ->schema([
                Textarea::make('description')
                    ->label('Açıklama')
                    ->helperText('Neden ters kaydedildiğini yazın.')
                    ->required()
                    ->rows(2),
            ])
            ->modalSubmitActionLabel('Ters kaydet')
            ->action(function (StockMovement $record, array $data, Action $action): void {
                try {
                    $correction = app(StockService::class)->correct($record, Auth::user(), $data['description']);
                } catch (BusinessRuleException $exception) {
                    BusinessRuleNotification::send($exception);
                    $action->halt();
                }

                Notification::make()
                    ->success()
                    ->title('Ters kayıt oluşturuldu')
                    ->body('Yeni bakiye: '.Quantity::format($correction->balance_after, $record->part->unit->name))
                    ->send();
            });
    }
}
