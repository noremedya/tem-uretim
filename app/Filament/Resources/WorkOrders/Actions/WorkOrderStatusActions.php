<?php

namespace App\Filament\Resources\WorkOrders\Actions;

use App\Exceptions\BusinessRuleException;
use App\Filament\Support\BusinessRuleNotification;
use App\Models\WorkOrder;
use App\Services\ProductionService;
use App\Services\WorkOrderService;
use App\Support\Quantity;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * İş emri durum aksiyonları (görüntüleme sayfası). Kim yapabilir: WorkOrderPolicy / WorkOrderService::canTransition.
 */
class WorkOrderStatusActions
{
    public static function start(): Action
    {
        return Action::make('start')
            ->label('Üretime başla')
            ->icon(Heroicon::OutlinedPlay)
            ->color('warning')
            ->authorize('start')
            ->requiresConfirmation()
            ->modalHeading('Üretime başla')
            ->modalDescription('İş emri "Üretimde" durumuna geçer.')
            ->action(self::run(fn (WorkOrder $record) => app(WorkOrderService::class)->start($record, Auth::user()), 'Üretim başladı'));
    }

    /**
     * Tamamla: her malzeme için gerçekleşen fire girilir (varsayılan: standart fire). Yetersiz malzemede
     * hiçbir şey değişmez ve eksikler listelenir.
     */
    public static function complete(): Action
    {
        return Action::make('complete')
            ->label('Tamamla')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->authorize('complete')
            ->modalHeading('İş emrini tamamla')
            ->modalDescription(fn (WorkOrder $record): string => $record->motor_model_id !== null
                ? 'Malzemeler düşülür, motorlar seri numarasıyla stoğa girer. Gerçekleşen fireyi kontrol edin.'
                : 'Malzemeler düşülür, ürün stoğa girer. Gerçekleşen fireyi kontrol edin.')
            ->modalSubmitActionLabel('Tamamla')
            ->fillForm(fn (WorkOrder $record): array => [
                'scraps' => $record->materials->mapWithKeys(fn ($material) => [
                    $material->part_id => Quantity::toInput((string) $material->planned_scrap),
                ])->all(),
            ])
            ->schema(fn (WorkOrder $record): array => [
                Section::make('Gerçekleşen fire')
                    ->columns(2)
                    ->schema($record->materials()->with('part.unit')->orderBy('id')->get()->map(
                        fn ($material) => TextInput::make("scraps.{$material->part_id}")
                            ->label("{$material->part->code} — {$material->part->name}")
                            ->suffix($material->part->unit->name)
                            ->helperText(sprintf(
                                'Net %s, standart fire %s',
                                Quantity::format((string) $material->required_quantity, $material->part->unit->name),
                                Quantity::format((string) $material->planned_scrap, $material->part->unit->name),
                            ))
                            ->inputMode('decimal')
                            ->required()
                            ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                                if (! Quantity::isValid($value) || Quantity::compare(Quantity::parse($value), '0') < 0) {
                                    $fail('Geçerli bir miktar girin (0 veya üstü).');
                                }
                            }),
                    )->all()),
            ])
            ->action(self::run(
                fn (WorkOrder $record, array $data) => app(ProductionService::class)->complete($record, Auth::user(), $data['scraps'] ?? []),
                'İş emri tamamlandı',
            ));
    }

    public static function revertToPlanned(): Action
    {
        return Action::make('revertToPlanned')
            ->label('Planlamaya geri al')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('gray')
            ->authorize('revertToPlanned')
            ->modalHeading('Planlamaya geri al')
            ->schema([self::reason()])
            ->action(self::run(fn (WorkOrder $record, array $data) => app(WorkOrderService::class)->revertToPlanned($record, Auth::user(), $data['reason']), 'İş emri planlamaya geri alındı'));
    }

    public static function cancel(): Action
    {
        return Action::make('cancel')
            ->label('İptal et')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->authorize('cancel')
            ->modalHeading('İş emrini iptal et')
            ->modalDescription('İptal son durumdur, geri alınamaz.')
            ->modalSubmitActionLabel('İptal et')
            ->schema([self::reason()])
            ->action(self::run(fn (WorkOrder $record, array $data) => app(WorkOrderService::class)->cancel($record, Auth::user(), $data['reason']), 'İş emri iptal edildi'));
    }

    public static function refreshMaterials(): Action
    {
        return Action::make('refreshMaterials')
            ->label('Reçeteyi yenile')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            ->authorize('refreshMaterials')
            ->requiresConfirmation()
            ->modalHeading('Reçeteyi yenile')
            ->modalDescription('Malzeme listesi güncel reçeteden yeniden kopyalanır.')
            ->action(self::run(fn (WorkOrder $record) => app(WorkOrderService::class)->refreshMaterials($record, $record->lock_version), 'Malzeme listesi güncellendi'));
    }

    private static function reason(): Textarea
    {
        return Textarea::make('reason')->label('Açıklama')->required()->maxLength(1000)->rows(3);
    }

    /**
     * @param  Closure(WorkOrder, array): mixed  $callback
     */
    private static function run(Closure $callback, string $successTitle): Closure
    {
        return function (WorkOrder $record, array $data, Action $action) use ($callback, $successTitle): void {
            try {
                $callback($record, $data);
            } catch (BusinessRuleException $exception) {
                BusinessRuleNotification::send($exception);
                $action->halt();
            }

            $record->refresh();

            Notification::make()->success()->title($successTitle)->send();
        };
    }
}
