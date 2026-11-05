<?php

namespace App\Filament\Resources\WorkOrders\RelationManagers;

use App\Enums\PartType;
use App\Enums\Permission;
use App\Enums\WorkOrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\WorkOrders\WorkOrderResource;
use App\Filament\Support\BusinessRuleNotification;
use App\Models\Part;
use App\Models\StockBalance;
use App\Models\Warehouse;
use App\Models\WorkOrder;
use App\Models\WorkOrderMaterial;
use App\Services\StockService;
use App\Services\WorkOrderService;
use App\Support\Quantity;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

/**
 * İş emrinin malzeme listesi ve yetersiz malzeme uyarısı. Eksik = fire dahil ihtiyaç − satırın deposundaki stok;
 * başka iş emirlerinin ihtiyacı hesaba katılmaz.
 *
 * Planlı/üretimdeki iş emrinde eksik satırdan ("Eksiği tamamla", kod veya ada tıklayarak) doğrudan işlem yapılır:
 * başka depoda stok varsa satırın deposuna transfer; hammadde, komponent ve sarfta stok girişi (stok yetkisi);
 * yarı mamulde yeni iş emri (iş emri yönetimi yetkisi) veya dışarıdan (fason) gelen için stok girişi.
 */
class MaterialsRelationManager extends RelationManager
{
    protected static string $relationship = 'materials';

    protected static ?string $title = 'Malzemeler';

    /** Stok girişinin çift gönderim anahtarı; pencere her açıldığında yenilenir. */
    #[Locked]
    public string $submissionKey = '';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Auth::user()?->can('view', $ownerRecord) ?? false;
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['workOrder', 'part.unit', 'part.stockBalances.warehouse', 'warehouse', 'scrapPart']))
            ->defaultSort('id')
            ->paginated(false)
            ->columns([
                TextColumn::make('part.code')->label('Kod')->action($this->resolveShortageAction()),
                TextColumn::make('part.name')->label('Malzeme')->action($this->resolveShortageAction())
                    ->description(fn (WorkOrderMaterial $record): ?string => $record->scrapPart ? "Fire → {$record->scrapPart->code}" : null),
                TextColumn::make('part.type')->label('Tür')->badge()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('required_quantity')
                    ->label('Net ihtiyaç')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state, WorkOrderMaterial $record): string => Quantity::format($state, $record->part->unit->name)),
                TextColumn::make('planned_scrap')
                    ->label('Standart fire')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state, WorkOrderMaterial $record): string => Quantity::format($state, $record->part->unit->name)
                        .($record->scrap_quantity_per_unit !== null ? '' : ' (%'.Quantity::format((string) $record->scrap_rate).')')),
                TextColumn::make('actual_scrap')
                    ->label('Gerçekleşen fire')
                    ->alignEnd()
                    ->placeholder('—')
                    ->formatStateUsing(fn ($state, WorkOrderMaterial $record): string => Quantity::format($state, $record->part->unit->name)),
                TextColumn::make('warehouse.name')
                    ->label('Depo'),
                TextColumn::make('available')
                    ->label('Stok')
                    ->alignEnd()
                    ->state(fn (WorkOrderMaterial $record): string => Quantity::format($record->available(), $record->part->unit->name))
                    // Başka depolarda stok varsa ipucu (ör. "HAM: 120 kg").
                    ->description(fn (WorkOrderMaterial $record): ?string => static::elsewhereText($record)),
                TextColumn::make('missing')
                    ->label('Eksik')
                    ->alignEnd()
                    ->state(function (WorkOrderMaterial $record): string {
                        $missing = static::missing($record);

                        return Quantity::compare($missing, '0') > 0 ? Quantity::format($missing, $record->part->unit->name) : '—';
                    })
                    ->color(fn (string $state): ?string => $state !== '—' ? 'danger' : null)
                    ->weight(fn (string $state): ?string => $state !== '—' ? 'bold' : null),
            ])
            ->recordActions([
                $this->resolveShortageAction(),
            ]);
    }

    /** Eksik miktar (0 veya pozitif); tam sayılı birimde yukarı yuvarlanır. */
    public static function missing(WorkOrderMaterial $material): string
    {
        $missing = Quantity::sub($material->totalRequirement(), $material->available());

        if (Quantity::compare($missing, '0') <= 0) {
            return '0.000';
        }

        return $material->part->unit->allows_decimal ? $missing : Quantity::ceil($missing, 0);
    }

    /** @return Collection<int, StockBalance> satırın deposu dışında stoğu pozitif olan depolar */
    private static function elsewhere(WorkOrderMaterial $material): Collection
    {
        return $material->part->stockBalances
            ->filter(fn (StockBalance $b) => $b->warehouse_id !== $material->warehouse_id && Quantity::compare((string) $b->quantity, '0') > 0)
            ->sortBy(fn (StockBalance $b) => $b->warehouse->code)
            ->values();
    }

    private static function elsewhereText(WorkOrderMaterial $material): ?string
    {
        $text = static::elsewhere($material)
            ->map(fn (StockBalance $b) => $b->warehouse->code.': '.Quantity::format((string) $b->quantity, $material->part->unit->name))
            ->implode(', ');

        return $text === '' ? null : $text;
    }

    private function resolveShortageAction(): Action
    {
        return Action::make('resolveShortage')
            ->label('Eksiği tamamla')
            ->icon('heroicon-o-plus-circle')
            ->color('danger')
            ->modalSubmitActionLabel('Kaydet')
            ->visible(fn (WorkOrderMaterial $record): bool => $this->canResolve($record))
            ->modalHeading(fn (WorkOrderMaterial $record): string => "Eksik: {$record->part->code} {$record->part->name}")
            ->modalDescription(fn (WorkOrderMaterial $record): string => sprintf(
                'İhtiyaç (fire dahil) %s, %s stoku %s, eksik %s.',
                Quantity::format($record->totalRequirement(), $record->part->unit->name),
                $record->warehouse->name,
                Quantity::format($record->available(), $record->part->unit->name),
                Quantity::format(static::missing($record), $record->part->unit->name),
            ))
            ->fillForm(function (WorkOrderMaterial $record): array {
                // Pencere her açıldığında yeni çift gönderim anahtarı.
                $this->submissionKey = (string) Str::uuid();

                $source = static::elsewhere($record)->first();

                return [
                    'mode' => array_key_first($this->modesFor($record)),
                    'source_warehouse_id' => $source?->warehouse_id,
                    'quantity' => Quantity::toInput(static::missing($record)),
                    'planned_end' => ($this->workOrder()->planned_start ?? today())->toDateString(),
                ];
            })
            ->schema(fn (WorkOrderMaterial $record): array => [
                Radio::make('mode')
                    ->label('İşlem')
                    ->options($this->modesFor($record))
                    ->required()
                    ->live()
                    ->visible(count($this->modesFor($record)) > 1),
                Select::make('source_warehouse_id')
                    ->label("Kaynak depo (→ {$record->warehouse->name})")
                    ->options(fn (): array => static::elsewhere($record)->mapWithKeys(fn (StockBalance $b) => [
                        $b->warehouse_id => $b->warehouse->name.' — '.Quantity::format((string) $b->quantity, $record->part->unit->name),
                    ])->all())
                    ->required(fn (Get $get): bool => $get('mode') === 'transfer')
                    ->visible(fn (Get $get): bool => $get('mode') === 'transfer'),
                TextInput::make('quantity')
                    ->label(fn (Get $get): string => match ($get('mode')) {
                        'work_order' => 'Üretilecek adet',
                        'transfer' => 'Aktarılacak miktar',
                        default => 'Giriş miktarı',
                    }." ({$record->part->unit->name})")
                    ->required()
                    ->inputMode('decimal')
                    ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                        if (! Quantity::isValid($value) || Quantity::compare(Quantity::parse($value), '0') <= 0) {
                            $fail('Sıfırdan büyük geçerli bir miktar girin.');
                        }
                    }),
                DatePicker::make('planned_end')
                    ->label('Planlanan bitiş')
                    ->required()
                    ->native(false)
                    ->displayFormat('d.m.Y')
                    ->visible(fn (Get $get): bool => $get('mode') === 'work_order'),
                Textarea::make('description')
                    ->label('Açıklama')
                    ->rows(2)
                    ->placeholder(fn (): string => "{$this->workOrder()->work_order_number} eksiği")
                    ->visible(fn (Get $get): bool => $get('mode') !== 'work_order'),
            ])
            ->action(function (WorkOrderMaterial $record, array $data, Action $action): void {
                $mode = $data['mode'] ?? array_key_first($this->modesFor($record));

                if (! array_key_exists($mode, $this->modesFor($record))) {
                    abort(403);
                }

                try {
                    match ($mode) {
                        'work_order' => $this->openWorkOrder($record, $data),
                        'transfer' => $this->transfer($record, $data),
                        default => $this->receive($record, $data),
                    };
                } catch (BusinessRuleException $exception) {
                    BusinessRuleNotification::send($exception);
                    $action->halt();
                }
            });
    }

    private function receive(WorkOrderMaterial $material, array $data): void
    {
        $description = filled($data['description'] ?? null)
            ? trim($data['description'])
            : ($material->part->type === PartType::SemiFinished ? 'Dışarıdan (fason) temin — ' : '')."{$this->workOrder()->work_order_number} eksiği";

        $movement = app(StockService::class)->stockIn($material->part, $data['quantity'], Auth::user(), $description, $this->submissionKey, $material->warehouse);

        // Aynı anahtarla ikinci istek hareket oluşturmaz ve bildirimi tekrarlamaz.
        if ($movement->wasRecentlyCreated) {
            Notification::make()->success()
                ->title("Stok girişi kaydedildi: {$material->part->code}")
                ->body('Yeni bakiye: '.Quantity::format((string) $movement->balance_after, $material->part->unit->name))
                ->send();
        }

        $this->submissionKey = (string) Str::uuid();
    }

    private function transfer(WorkOrderMaterial $material, array $data): void
    {
        $from = Warehouse::query()->findOrFail($data['source_warehouse_id']);
        $description = filled($data['description'] ?? null) ? trim($data['description']) : "{$this->workOrder()->work_order_number} eksiği";

        $movements = app(StockService::class)->transfer($material->part, $from, $material->warehouse, $data['quantity'], Auth::user(), $description, $this->submissionKey);
        $in = $movements->last();

        if ($movements->first()->wasRecentlyCreated) {
            Notification::make()->success()
                ->title("Transfer kaydedildi: {$material->part->code}")
                ->body("{$from->name} → {$material->warehouse->name}. Yeni bakiye: ".Quantity::format((string) $in->balance_after, $material->part->unit->name))
                ->send();
        }

        $this->submissionKey = (string) Str::uuid();
    }

    private function openWorkOrder(WorkOrderMaterial $material, array $data): void
    {
        $owner = $this->workOrder();
        [$product, $quantity] = $this->workOrderTarget($material, $data['quantity']);

        $created = app(WorkOrderService::class)->create([
            'product_part_id' => $product->getKey(),
            'quantity' => $quantity,
            'planned_end' => $data['planned_end'],
            'notes' => "{$owner->work_order_number} için eksik yarı mamul",
        ], Auth::user());

        Notification::make()->success()
            ->title("İş emri açıldı: {$created->work_order_number}")
            ->body("{$product->code} — ".Quantity::format((string) $created->quantity, $product->unit->name))
            ->actions([Action::make('open')->label('İş emrini aç')->url(WorkOrderResource::getUrl('view', ['record' => $created]))])
            ->send();
    }

    /**
     * Açılacak iş emrinin ürünü ve adedi: eş ürün kendi başına üretilmez, ana ürünü üretilir (adet = ihtiyaç ÷ birim
     * başına eş ürün, yukarı yuvarlı).
     *
     * @return array{0: Part, 1: string}
     */
    private function workOrderTarget(WorkOrderMaterial $material, string $quantity): array
    {
        $link = $material->part->coProductOf()->with('parent.unit')->first();

        if ($link === null) {
            return [$material->part, $quantity];
        }

        $units = Quantity::ceil(bcdiv(Quantity::parse($quantity), (string) $link->quantity_per_unit, 6), $link->parent->unit->allows_decimal ? Quantity::SCALE : 0);

        return [$link->parent, $units];
    }

    /** @return array<string, string> kullanıcının bu malzeme için yapabileceği işlemler */
    private function modesFor(WorkOrderMaterial $material): array
    {
        $user = Auth::user();
        $canStock = $user->can(Permission::StockMove) && $material->part->is_active;
        $modes = [];

        // Başka depoda stok varsa önce transfer önerilir.
        if ($canStock && static::elsewhere($material)->isNotEmpty()) {
            $modes['transfer'] = 'Transfer (başka depodan → '.$material->warehouse->name.')';
        }

        if ($material->part->type === PartType::SemiFinished) {
            if ($user->can('create', WorkOrder::class)) {
                $main = $material->part->coProductOf()->with('parent')->first()?->parent;
                $modes['work_order'] = $main !== null
                    ? "İş emri aç: {$main->code} (bu ürün onunla birlikte çıkar)"
                    : 'İş emri aç (fabrikada üretilecek)';
            }

            if ($canStock) {
                $modes['stock_in'] = 'Stok girişi (dışarıdan / fason geldi)';
            }

            return $modes;
        }

        if ($canStock && $material->part->type !== PartType::FinishedGood) {
            $modes['stock_in'] = 'Stok girişi';
        }

        return $modes;
    }

    private function canResolve(WorkOrderMaterial $material): bool
    {
        return in_array($this->workOrder()->status, [WorkOrderStatus::Planned, WorkOrderStatus::InProgress], true)
            && Quantity::compare(static::missing($material), '0') > 0
            && $this->modesFor($material) !== [];
    }

    private function workOrder(): WorkOrder
    {
        /** @var WorkOrder */
        return $this->getOwnerRecord();
    }
}
