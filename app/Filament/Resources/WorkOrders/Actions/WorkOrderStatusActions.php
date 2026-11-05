<?php

namespace App\Filament\Resources\WorkOrders\Actions;

use App\Enums\WorkOrderStatus;
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
use Filament\Schemas\Components\Utilities\Set;
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
     * Üretim kaydı (kısım kısım tamamlama): bu sefer üretilen adet (varsayılan kalan adet) ve her malzeme için bu
     * kaydın gerçekleşen firesi (varsayılan: o adedin standart firesi) girilir. Kalan adet üretilince iş emri
     * tamamlanır. Yetersiz malzemede hiçbir şey değişmez ve eksikler listelenir.
     */
    public static function complete(): Action
    {
        return Action::make('complete')
            ->label('Üretim kaydı')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->authorize('complete')
            ->modalHeading('Üretim kaydı')
            ->modalDescription(fn (WorkOrder $record): string => sprintf(
                '%s %s; üretilen %s, kalan %s. %s',
                $record->work_order_number,
                Quantity::format((string) $record->quantity, self::unit($record)),
                Quantity::format((string) $record->produced_quantity),
                Quantity::format($record->remainingQuantity()),
                $record->motor_model_id !== null
                    ? 'Malzemeler düşülür, motorlar bugünün tarihli seri numarasıyla stoğa girer. Kalanın tamamı girilirse iş emri tamamlanır.'
                    : 'Malzemeler düşülür, ürün stoğa girer. Kalanın tamamı girilirse iş emri tamamlanır.',
            ))
            ->modalSubmitActionLabel('Kaydet')
            ->fillForm(fn (WorkOrder $record): array => [
                'quantity' => Quantity::toInput($record->remainingQuantity()),
                'scraps' => self::standardScraps($record, $record->remainingQuantity()),
            ])
            ->schema(fn (WorkOrder $record): array => [
                TextInput::make('quantity')
                    ->label($record->motor_model_id !== null ? 'Üretilen motor adedi' : 'Üretilen miktar')
                    ->suffix(self::unit($record))
                    ->helperText('Kalan: '.Quantity::format($record->remainingQuantity(), self::unit($record)))
                    ->inputMode('decimal')
                    ->required()
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (?string $state, Set $set) use ($record): void {
                        if (Quantity::isValid($state) && Quantity::compare(Quantity::parse($state), '0') > 0
                            && Quantity::compare(Quantity::parse($state), $record->remainingQuantity()) <= 0) {
                            $set('scraps', self::standardScraps($record, Quantity::parse($state)));
                        }
                    }),
                Section::make('Gerçekleşen fire (bu kayıt)')
                    ->columns(2)
                    ->schema($record->materials()->with('part.unit')->orderBy('id')->get()->map(
                        fn ($material) => TextInput::make("scraps.{$material->part_id}")
                            ->label("{$material->part->code} — {$material->part->name}")
                            ->suffix($material->part->unit->name)
                            ->helperText(sprintf(
                                'Birim başına %s; iş emrinin tamamında standart fire %s',
                                Quantity::format((string) $material->quantity_per_unit, $material->part->unit->name),
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
            ->action(function (WorkOrder $record, array $data, Action $action): void {
                try {
                    app(ProductionService::class)->recordProduction($record, Auth::user(), $data['quantity'] ?? null, $data['scraps'] ?? []);
                } catch (BusinessRuleException $exception) {
                    BusinessRuleNotification::send($exception);
                    $action->halt();
                }

                $record->refresh();

                Notification::make()->success()
                    ->title($record->status === WorkOrderStatus::Completed ? 'İş emri tamamlandı' : 'Üretim kaydedildi')
                    ->body($record->status === WorkOrderStatus::Completed ? null : sprintf(
                        'Üretilen %s, kalan %s.',
                        Quantity::format((string) $record->produced_quantity),
                        Quantity::format($record->remainingQuantity()),
                    ))
                    ->send();
            });
    }

    /**
     * $quantity adetlik kaydın malzeme başına standart firesi (birikimli fark; ProductionService ile aynı hesap).
     *
     * @return array<int, ?string> part_id => form değeri
     */
    private static function standardScraps(WorkOrder $record, string $quantity): array
    {
        $before = (string) $record->produced_quantity;
        $after = Quantity::add($before, $quantity);

        return $record->materials()->with('part.unit')->get()->mapWithKeys(function ($material) use ($before, $after): array {
            $whole = ! $material->part->unit->allows_decimal;

            return [$material->part_id => Quantity::toInput(Quantity::sub($material->standardScrapFor($after, $whole), $material->standardScrapFor($before, $whole)))];
        })->all();
    }

    private static function unit(WorkOrder $record): string
    {
        return $record->loadMissing('product.unit')->product->unit->name;
    }

    /** Son üretim kaydını geri al (yalnızca yönetici, açıklama zorunlu). */
    public static function reverse(): Action
    {
        return Action::make('reverse')
            ->label('Son üretim kaydını geri al')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('danger')
            ->authorize('reverse')
            ->modalHeading('Son üretim kaydını geri al')
            ->modalDescription(function (WorkOrder $record): string {
                $last = $record->productions()->whereNull('reversed_at')->orderByDesc('id')->first();

                return ($last !== null ? sprintf('Geri alınacak kayıt: %s, %s. ', $last->created_at->format('d.m.Y H:i'), Quantity::format((string) $last->quantity, self::unit($record))) : '')
                    .'Kaydın sarf, fire ve üretimden giriş hareketleri ters kaydedilir; motorları "iptal edildi" olur (seri numaraları tekrar kullanılmaz); tamamlanmış iş emri "Üretimde" durumuna döner. Sevk edilmiş motor varsa veya üretilen ürün kullanılmışsa yapılamaz.';
            })
            ->modalSubmitActionLabel('Geri al')
            ->schema([self::reason()])
            ->action(self::run(fn (WorkOrder $record, array $data) => app(ProductionService::class)->reverse($record, Auth::user(), $data['reason']), 'Üretim kaydı geri alındı'));
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
