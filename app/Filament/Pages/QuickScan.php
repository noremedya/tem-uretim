<?php

namespace App\Filament\Pages;

use App\Enums\PartType;
use App\Enums\Permission;
use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\MotorUnits\Actions\ShipMotorUnitsAction;
use App\Filament\Resources\WorkOrders\Actions\WorkOrderStatusActions;
use App\Filament\Support\BusinessRuleNotification;
use App\Models\MotorUnit;
use App\Models\Part;
use App\Models\Warehouse;
use App\Models\WorkOrder;
use App\Services\QuickScanService;
use App\Services\StockService;
use App\Support\Quantity;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

/**
 * Hızlı İşlem (CLAUDE.md bölüm 7): USB okuyucu veya kamerayla okunan kod parça, motor veya iş emrine çözülür;
 * yetkiye göre hızlı işlem butonları gösterilir. Tüm işlemler servisler ve mevcut aksiyonlar üzerinden yapılır.
 */
class QuickScan extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQrCode;

    protected static ?int $navigationSort = -10;

    protected static ?string $navigationLabel = 'Hızlı İşlem';

    protected static ?string $title = 'Hızlı İşlem';

    protected static ?string $slug = 'hizli-islem';

    protected string $view = 'filament.pages.quick-scan';

    public string $code = '';

    #[Locked]
    public ?string $resultType = null;

    #[Locked]
    public ?int $resultId = null;

    #[Locked]
    public ?string $message = null;

    #[Locked]
    public ?string $lastCode = null;

    /** Hızlı stok işleminin çift gönderim anahtarı; başarılı işlemden sonra yenilenir. */
    #[Locked]
    public string $submissionKey = '';

    public function mount(): void
    {
        $this->submissionKey = (string) Str::uuid();
    }

    public function scan(): void
    {
        $result = app(QuickScanService::class)->resolve($this->code, Auth::user());

        $this->lastCode = trim($this->code);
        $this->code = '';
        $this->resultType = $result['type'];
        $this->resultId = $result['record']?->getKey();
        $this->message = $result['message'];
        $this->submissionKey = (string) Str::uuid();

        $this->dispatch('quick-scan-done', status: $result['record'] !== null ? 'success' : 'error');
    }

    public function part(): ?Part
    {
        return $this->resultType === 'part' ? Part::query()->with(['unit', 'stockBalances.warehouse'])->find($this->resultId) : null;
    }

    public function motor(): ?MotorUnit
    {
        return $this->resultType === 'motor' ? MotorUnit::query()->with(['motorModel', 'workOrder', 'order.customer', 'shippedBy', 'warehouse'])->find($this->resultId) : null;
    }

    public function workOrder(): ?WorkOrder
    {
        $workOrder = $this->resultType === 'work_order' ? WorkOrder::query()->with(['product.unit', 'assignedUser', 'materials'])->find($this->resultId) : null;

        return $workOrder !== null && Auth::user()->can('view', $workOrder) ? $workOrder : null;
    }

    public function stockInAction(): Action
    {
        return $this->stockAction('stockIn', 'Stok girişi', 'success', descriptionRequired: false);
    }

    public function stockOutAction(): Action
    {
        return $this->stockAction('stockOut', 'Stok çıkışı', 'danger', descriptionRequired: true);
    }

    public function startAction(): Action
    {
        return WorkOrderStatusActions::start()->record(fn (): ?WorkOrder => $this->workOrder())->after(fn () => $this->done())->size('xl');
    }

    public function completeAction(): Action
    {
        return WorkOrderStatusActions::complete()->record(fn (): ?WorkOrder => $this->workOrder())->after(fn () => $this->done())->size('xl');
    }

    public function shipAction(): Action
    {
        return ShipMotorUnitsAction::single()->record(fn (): ?MotorUnit => $this->motor())->after(fn () => $this->done())->size('xl');
    }

    private function stockAction(string $name, string $label, string $color, bool $descriptionRequired): Action
    {
        return Action::make($name)
            ->label($label)
            ->color($color)
            ->size('xl')
            ->visible(fn (): bool => ($part = $this->part()) !== null
                && Auth::user()->can(Permission::StockMove)
                && $part->is_active
                && $part->type !== PartType::FinishedGood)
            ->modalHeading(fn (): string => "{$label}: {$this->part()?->code} {$this->part()?->name}")
            // Hızlı işlem ürünün varsayılan deposunda yapılır; başka depo için Stok İşlemi sayfası.
            ->modalDescription(fn (): ?string => ($part = $this->part()) !== null
                ? 'Depo: '.(Warehouse::query()->find($part->defaultWarehouseId())?->name ?? '—').' (başka depo için Stok İşlemi sayfası)'
                : null)
            ->schema([
                TextInput::make('quantity')
                    ->label(fn (): string => 'Miktar ('.$this->part()?->unit->name.')')
                    ->required()
                    ->autofocus()
                    ->inputMode('decimal')
                    ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                        if (! Quantity::isValid($value) || Quantity::compare(Quantity::parse($value), '0') <= 0) {
                            $fail('Sıfırdan büyük geçerli bir miktar girin.');
                        }
                    }),
                Textarea::make('description')->label('Açıklama')->rows(2)->required($descriptionRequired),
            ])
            ->action(function (array $data, Action $action) use ($name): void {
                $part = $this->part();

                try {
                    $movement = $name === 'stockIn'
                        ? app(StockService::class)->stockIn($part, $data['quantity'], Auth::user(), $data['description'] ?? null, $this->submissionKey)
                        : app(StockService::class)->stockOut($part, $data['quantity'], Auth::user(), $data['description'] ?? null, $this->submissionKey);
                } catch (BusinessRuleException $exception) {
                    BusinessRuleNotification::send($exception);
                    $this->dispatch('quick-scan-done', status: 'error');
                    $action->halt();
                }

                // Aynı anahtarla ikinci istek hareket oluşturmaz ve bildirimi tekrarlamaz.
                if ($movement->wasRecentlyCreated) {
                    Notification::make()->success()
                        ->title("{$movement->type->label()} kaydedildi")
                        ->body('Yeni bakiye: '.Quantity::format((string) $movement->balance_after, $part->unit->name))
                        ->send();
                }

                $this->submissionKey = (string) Str::uuid();
                $this->done();
            });
    }

    private function done(): void
    {
        $this->dispatch('quick-scan-done', status: 'success');
    }

    /** Sonuç kaydı (görünüm için). */
    public function record(): ?Model
    {
        return $this->part() ?? $this->motor() ?? $this->workOrder();
    }
}
