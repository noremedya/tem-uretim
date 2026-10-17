<?php

namespace App\Filament\Support;

use App\Enums\WorkOrderStatus;
use App\Models\MotorUnit;
use App\Models\WorkOrder;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Illuminate\Database\Eloquent\Collection;

/** QR etiket yazdırma butonları (Aşama 12). Etiket sayfası yeni sekmede açılır ve yazdırma penceresini getirir. */
class LabelActions
{
    /** Motor detay sayfası: bu motorun etiketi. */
    public static function motor(): Action
    {
        return Action::make('printLabel')
            ->label('Etiket yazdır')
            ->icon('heroicon-o-printer')
            ->color('gray')
            ->url(fn (MotorUnit $record): string => route('labels.motors', ['ids' => $record->getKey()]))
            ->openUrlInNewTab();
    }

    /** Motor listesi: seçili motorların etiketleri (aynı sekmede; etiket sayfasında "Geri dön" vardır). */
    public static function motorsBulk(): BulkAction
    {
        return BulkAction::make('printLabels')
            ->label('Etiket yazdır')
            ->icon('heroicon-o-printer')
            ->color('gray')
            ->action(fn (Collection $records) => redirect()->route('labels.motors', ['ids' => collect($records->modelKeys())->sort()->implode(',')]))
            ->deselectRecordsAfterCompletion();
    }

    /** İş emri detay sayfası: iş emri etiketi. */
    public static function workOrder(): Action
    {
        return Action::make('printWorkOrderLabel')
            ->label('Etiket yazdır')
            ->icon('heroicon-o-printer')
            ->color('gray')
            ->url(fn (WorkOrder $record): string => route('labels.work-order', $record))
            ->openUrlInNewTab();
    }

    /** Tamamlanmış mamul iş emri: ürettiği motorların etiketleri. */
    public static function workOrderMotors(): Action
    {
        return Action::make('printMotorLabels')
            ->label('Motor etiketleri')
            ->icon('heroicon-o-qr-code')
            ->color('gray')
            ->visible(fn (WorkOrder $record): bool => $record->status === WorkOrderStatus::Completed && $record->motor_model_id !== null)
            ->url(fn (WorkOrder $record): string => route('labels.motors', ['work_order' => $record->getKey()]))
            ->openUrlInNewTab();
    }
}
