<?php

namespace App\Filament\Resources\MotorUnits\Actions;

use App\Exceptions\BusinessRuleException;
use App\Filament\Support\BusinessRuleNotification;
use App\Models\MotorModel;
use App\Models\MotorUnit;
use App\Models\Warehouse;
use App\Services\ProductionService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/** Açılış motoru (yönetici) ve motor iadesi (yönetici, depo). İş kuralları ProductionService'tedir. */
class MotorStockActions
{
    public static function registerOpening(): Action
    {
        return Action::make('registerOpening')
            ->label('Açılış motoru')
            ->icon(Heroicon::OutlinedArchiveBoxArrowDown)
            ->visible(fn (): bool => Gate::allows('registerOpening', MotorUnit::class))
            ->modalHeading('Açılış motoru gir')
            ->modalDescription('Sistem öncesinden stokta olan motorlar içindir. Üretim sayılarına girmez.')
            ->modalSubmitActionLabel('Kaydet')
            ->schema([
                Select::make('motor_model_id')
                    ->label('Model')
                    ->required()
                    ->searchable()
                    ->options(fn (): array => MotorModel::query()->where('is_active', true)->orderBy('code')->get()
                        ->mapWithKeys(fn (MotorModel $m) => [$m->getKey() => "{$m->code} — {$m->name}"])->all()),
                self::warehouse()->default(fn (): ?int => Warehouse::query()->where('is_shipping', true)->value('id')),
                DatePicker::make('production_date')->label('Üretim tarihi')->native(false)->displayFormat('d.m.Y')->maxDate(today())
                    ->helperText('Bilinmiyorsa boş bırakın, bugün yazılır.'),
                Textarea::make('serials')
                    ->label('Seri numaraları')
                    ->rows(5)
                    ->helperText('Etiketindeki numara: her satıra bir tane.'),
                TextInput::make('generate')
                    ->label('Etiketi olmayan motor adedi')
                    ->integer()
                    ->minValue(0)
                    ->maxValue(ProductionService::MAX_OPENING_MOTORS)
                    ->default(0)
                    ->helperText('Bu kadar motora sistem seri numarası verir.'),
                Textarea::make('note')->label('Not')->rows(2)->maxLength(1000),
            ])
            ->action(function (array $data, Action $action): void {
                try {
                    $units = app(ProductionService::class)->registerOpeningMotors(
                        MotorModel::query()->findOrFail($data['motor_model_id']),
                        Warehouse::query()->findOrFail($data['warehouse_id']),
                        preg_split('/\R/u', (string) ($data['serials'] ?? '')),
                        (int) ($data['generate'] ?? 0),
                        $data['production_date'] ?? null,
                        Auth::user(),
                        $data['note'] ?? null,
                    );
                } catch (BusinessRuleException $exception) {
                    BusinessRuleNotification::send($exception);
                    $action->halt();
                }

                Notification::make()->success()->title("{$units->count()} açılış motoru kaydedildi")->body($units->pluck('serial_number')->take(10)->implode(', '))->send();
            });
    }

    public static function cancelOpening(): Action
    {
        return Action::make('cancelOpening')
            ->label('Açılış kaydını iptal et')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->authorize('cancelOpening')
            ->modalDescription('Motor iptal edilir ve stoktan düşer. Seri numarası tekrar kullanılamaz.')
            ->schema([Textarea::make('reason')->label('İptal açıklaması')->required()->maxLength(1000)->rows(3)])
            ->action(function (MotorUnit $record, array $data, Action $action): void {
                try {
                    app(ProductionService::class)->cancelOpeningMotor($record, Auth::user(), $data['reason']);
                } catch (BusinessRuleException $exception) {
                    BusinessRuleNotification::send($exception);
                    $action->halt();
                }

                $record->refresh();
                Notification::make()->success()->title('Açılış kaydı iptal edildi')->send();
            });
    }

    public static function receiveReturn(): Action
    {
        return Action::make('receiveReturn')
            ->label('İade al')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('warning')
            ->authorize('receiveReturn')
            ->modalHeading('Motor iadesi al')
            ->modalDescription('Motor seçilen depoda yeniden stokta görünür. İade faturası ayrı kesilir; arızalı motor için İade deposunu seçin.')
            ->modalSubmitActionLabel('İadeyi kaydet')
            ->schema([
                self::warehouse(),
                Textarea::make('reason')->label('İade açıklaması')->required()->maxLength(1000)->rows(3)->placeholder('ör. arıza nedeni, müşteri talebi'),
            ])
            ->action(function (MotorUnit $record, array $data, Action $action): void {
                try {
                    app(ProductionService::class)->receiveReturn($record, Warehouse::query()->findOrFail($data['warehouse_id']), Auth::user(), $data['reason']);
                } catch (BusinessRuleException $exception) {
                    BusinessRuleNotification::send($exception);
                    $action->halt();
                }

                $record->refresh();
                Notification::make()->success()->title('İade alındı, motor stokta')->send();
            });
    }

    private static function warehouse(): Select
    {
        return Select::make('warehouse_id')
            ->label('Depo')
            ->required()
            ->options(fn (): array => Warehouse::query()->where('is_active', true)->orderBy('code')->pluck('name', 'id')->all());
    }
}
