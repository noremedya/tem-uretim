<?php

namespace App\Filament\Resources\MotorUnits\Actions;

use App\Exceptions\BusinessRuleException;
use App\Filament\Support\BusinessRuleNotification;
use App\Models\MotorUnit;
use App\Services\ProductionService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Sevk: stoktaki motorlar "sevk edildi" olur, mamul stoğundan düşülür (yönetici ve depo).
 */
class ShipMotorUnitsAction
{
    public static function single(): Action
    {
        return Action::make('ship')
            ->label('Sevk et')
            ->icon(Heroicon::OutlinedTruck)
            ->color('primary')
            ->authorize('ship')
            ->modalHeading('Motoru sevk et')
            ->schema([self::note()])
            ->action(function (MotorUnit $record, array $data, Action $action): void {
                self::ship([$record], $data, $action);
                $record->refresh();
            });
    }

    public static function bulk(): BulkAction
    {
        return BulkAction::make('ship')
            ->label('Seçilenleri sevk et')
            ->icon(Heroicon::OutlinedTruck)
            ->color('primary')
            ->authorize('shipAny')
            ->modalHeading('Seçilen motorları sevk et')
            ->modalDescription('Yalnızca stoktaki motorlar sevk edilebilir; biri bile uygun değilse hiçbiri sevk edilmez.')
            ->schema([self::note()])
            ->deselectRecordsAfterCompletion()
            ->action(fn (Collection $records, array $data, BulkAction $action) => self::ship($records, $data, $action));
    }

    private static function note(): Textarea
    {
        return Textarea::make('note')->label('Sevk notu')->placeholder('ör. irsaliye no, araç plakası')->maxLength(1000)->rows(2);
    }

    private static function ship(iterable $records, array $data, Action $action): void
    {
        try {
            $shipped = app(ProductionService::class)->ship($records, Auth::user(), $data['note'] ?? null);
        } catch (BusinessRuleException $exception) {
            BusinessRuleNotification::send($exception);
            $action->halt();
        }

        Notification::make()->success()->title(sprintf('%d motor sevk edildi', $shipped->count()))->send();
    }
}
