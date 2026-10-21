<?php

namespace App\Filament\Resources\MotorUnits\Actions;

use App\Enums\MotorUnitStatus;
use App\Enums\Permission;
use App\Exceptions\BusinessRuleException;
use App\Filament\Support\BusinessRuleNotification;
use App\Models\MotorUnit;
use App\Models\Warehouse;
use App\Services\ProductionService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Mamul transferi: stoktaki motorlar başka depoya aktarılır (stok hareketi yetkisi: yönetici ve depo).
 */
class TransferMotorUnitsAction
{
    public static function single(): Action
    {
        return Action::make('transferMotor')
            ->label('Depoya aktar')
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->color('gray')
            ->visible(fn (MotorUnit $record): bool => self::allowed() && $record->status === MotorUnitStatus::InStock)
            ->modalHeading(fn (MotorUnit $record): string => "Motoru başka depoya aktar — {$record->serial_number}")
            ->schema(fn (MotorUnit $record): array => self::fields($record->warehouse_id))
            ->action(function (MotorUnit $record, array $data, Action $action): void {
                self::transfer([$record], $data, $action);
                $record->refresh();
            });
    }

    public static function bulk(): BulkAction
    {
        return BulkAction::make('transferMotors')
            ->label('Seçilenleri depoya aktar')
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->visible(fn (): bool => self::allowed())
            ->modalHeading('Seçilen motorları başka depoya aktar')
            ->modalDescription('Yalnızca stoktaki ve hedef depo dışındaki motorlar aktarılabilir; biri bile uygun değilse hiçbiri aktarılmaz.')
            ->schema(self::fields(null))
            ->deselectRecordsAfterCompletion()
            ->action(fn (Collection $records, array $data, BulkAction $action) => self::transfer($records, $data, $action));
    }

    private static function allowed(): bool
    {
        return Auth::user()?->can(Permission::StockMove) ?? false;
    }

    /** @return array<int, mixed> */
    private static function fields(?int $currentWarehouseId): array
    {
        return [
            Select::make('warehouse_id')
                ->label('Hedef depo')
                ->required()
                ->options(fn (): array => Warehouse::query()->where('is_active', true)
                    ->when($currentWarehouseId, fn ($q) => $q->whereKeyNot($currentWarehouseId))
                    ->orderBy('code')->pluck('name', 'id')->all()),
            Textarea::make('note')->label('Açıklama')->maxLength(1000)->rows(2),
        ];
    }

    private static function transfer(iterable $records, array $data, Action $action): void
    {
        try {
            $moved = app(ProductionService::class)->transferMotors($records, Warehouse::query()->findOrFail($data['warehouse_id']), Auth::user(), $data['note'] ?? null);
        } catch (BusinessRuleException $exception) {
            BusinessRuleNotification::send($exception);
            $action->halt();
        }

        Notification::make()->success()->title(sprintf('%d motor aktarıldı', $moved->count()))->send();
    }
}
