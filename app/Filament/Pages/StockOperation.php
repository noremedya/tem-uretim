<?php

namespace App\Filament\Pages;

use App\Enums\PartType;
use App\Enums\Permission;
use App\Enums\StockMovementType;
use App\Exceptions\BusinessRuleException;
use App\Filament\Support\BusinessRuleNotification;
use App\Models\Part;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\StockService;
use App\Support\Quantity;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use UnitEnum;

/**
 * Stok giriş / çıkış / sayım / iade / depolar arası transfer formu. Depo seçilir (ürün seçilince varsayılan
 * deposu gelir); transferde ayrıca hedef depo.
 *
 * Çift gönderim koruması yalnızca gönderim anahtarına dayanır:
 * - İstek sürerken kaydet butonu pasiftir (Filament, wire:loading) ve form ikinci kez gönderilemez
 *   (Alpine submitGuard; Enter ile gönderim dahil). Bu yalnızca kullanıcı deneyimi içindir.
 * - Her form gönderimi $submissionKey taşır; StockService aynı anahtarla ikinci hareket oluşturmaz, mevcut
 *   hareketi döndürür. Bu durumda ilk işlemin sonucu gösterilir, bildirim tekrarlanmaz.
 * - Başarılı işlemden sonra form sıfırlanır (miktar ve açıklama temizlenir; tür ve parça kalır) ve yeni
 *   anahtar üretilir. Aynı parça ve miktarla bilinçli ikinci işlem yeni bir hareket oluşturur.
 */
class StockOperation extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Stok';

    protected static ?int $navigationSort = 0;

    protected static ?string $navigationLabel = 'Stok İşlemi';

    protected static ?string $title = 'Stok İşlemi';

    protected static ?string $slug = 'stock-operation';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    #[Locked]
    public string $submissionKey = '';

    /** Son işlemin özeti (yeni bakiye dahil). */
    #[Locked]
    public ?array $lastResult = null;

    public static function canAccess(): bool
    {
        return Auth::user()?->can(Permission::StockMove) ?? false;
    }

    public function mount(): void
    {
        $this->submissionKey = (string) Str::uuid();

        $partId = request()->integer('part') ?: null;
        $part = $partId !== null ? Part::query()->whereKey($partId)->where('type', '!=', PartType::FinishedGood->value)->first() : null;

        $this->form->fill([
            'type' => StockMovementType::StockIn->value,
            'part_id' => $part?->getKey(),
            'warehouse_id' => $part?->defaultWarehouseId(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make()
                    ->columns(2)
                    ->schema([
                        ToggleButtons::make('type')
                            ->label('İşlem')
                            ->options(collect(StockMovementType::manualCases())->mapWithKeys(fn (StockMovementType $type) => [$type->value => $type->label()]))
                            ->colors(collect(StockMovementType::manualCases())->mapWithKeys(fn (StockMovementType $type) => [$type->value => $type->getColor()]))
                            ->icons([
                                StockMovementType::StockIn->value => Heroicon::OutlinedArrowDownTray,
                                StockMovementType::StockOut->value => Heroicon::OutlinedArrowUpTray,
                                StockMovementType::CountAdjustment->value => Heroicon::OutlinedClipboardDocumentCheck,
                                StockMovementType::Return->value => Heroicon::OutlinedArrowUturnLeft,
                                StockMovementType::Transfer->value => Heroicon::OutlinedArrowsRightLeft,
                            ])
                            ->inline()
                            ->required()
                            ->live()
                            ->columnSpanFull(),
                        Select::make('part_id')
                            ->label('Ürün')
                            ->placeholder('Kod, ad veya barkod ile arayın')
                            ->searchable()
                            ->required()
                            ->live()
                            ->getSearchResultsUsing(fn (string $search, Get $get): array => $this->searchParts($search, $get('type')))
                            ->getOptionLabelUsing(fn ($value): ?string => ($part = Part::query()->find($value)) ? $this->partLabel($part) : null)
                            // Ürün seçilince varsayılan deposu gelir.
                            ->afterStateUpdated(fn ($state, Set $set) => $set('warehouse_id', filled($state) ? Part::query()->find($state)?->defaultWarehouseId() : null))
                            ->columnSpanFull(),
                        Select::make('warehouse_id')
                            ->label(fn (Get $get): string => $get('type') === StockMovementType::Transfer->value ? 'Kaynak depo' : 'Depo')
                            ->options(fn (Get $get): array => $this->warehouseOptions($get('type'), source: true))
                            ->required()
                            ->live()
                            ->helperText(fn (Get $get): ?string => $this->balanceText($get('part_id'), $get('warehouse_id'))),
                        Select::make('target_warehouse_id')
                            ->label('Hedef depo')
                            ->options(fn (Get $get): array => collect($this->warehouseOptions(StockMovementType::Transfer->value, source: false))
                                ->except([(int) $get('warehouse_id')])->all())
                            ->required(fn (Get $get): bool => $get('type') === StockMovementType::Transfer->value)
                            ->visible(fn (Get $get): bool => $get('type') === StockMovementType::Transfer->value)
                            ->different('warehouse_id')
                            ->live()
                            ->helperText(fn (Get $get): ?string => $this->balanceText($get('part_id'), $get('target_warehouse_id'), withTotal: false)),
                        TextInput::make('quantity')
                            ->label(fn (Get $get): string => $get('type') === StockMovementType::CountAdjustment->value ? 'Sayılan miktar' : 'Miktar')
                            ->required()
                            ->inputMode('decimal')
                            ->autocomplete('off')
                            ->suffix(fn (Get $get): ?string => $this->unitName($get('part_id')))
                            ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                                if (! Quantity::isValid($value)) {
                                    $fail('Geçerli bir miktar girin (ör. 12 veya 12,5; en fazla 3 ondalık).');
                                }
                            })
                            ->helperText(fn (Get $get): ?string => $get('type') === StockMovementType::CountAdjustment->value
                                ? 'Rafta sayılan toplam miktarı girin; fark otomatik hesaplanır.'
                                : null),
                        Textarea::make('description')
                            ->label('Açıklama')
                            ->rows(2)
                            ->required(fn (Get $get): bool => StockMovementType::tryFrom((string) $get('type'))?->requiresDescription() ?? false)
                            ->helperText(fn (Get $get): ?string => match ($get('type')) {
                                StockMovementType::StockOut->value => 'Zorunlu. Tedarikçiye iade de çıkış olarak, açıklamayla girilir.',
                                StockMovementType::CountAdjustment->value => 'Zorunlu.',
                                StockMovementType::Return->value => 'Üretimden veya müşteriden depoya dönüş.',
                                default => null,
                            }),
                    ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Callout::make(fn (): ?string => $this->lastResult['title'] ?? null)
                    ->description(fn (): ?string => $this->lastResult['body'] ?? null)
                    ->status(fn (): string => $this->lastResult['status'] ?? 'success')
                    ->visible(fn (): bool => $this->lastResult !== null)
                    ->key('last-result'),
                Form::make([EmbeddedSchema::make('form')])
                    ->id('form')
                    ->livewireSubmitHandler('save')
                    // İstek sürerken ikinci gönderim tarayıcıda kesilir (resources/js/filament/submit-guard.js).
                    ->extraAttributes(['x-data' => "submitGuard({ action: 'save' })"])
                    ->footer([
                        Actions::make([
                            Action::make('save')
                                ->label('Kaydet')
                                ->submit('save')
                                ->keyBindings(['mod+s']),
                        ])->key('form-actions'),
                    ]),
            ]);
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $data = $this->form->getState();

        $type = StockMovementType::from($data['type']);
        $part = Part::query()->with('unit')->findOrFail($data['part_id']);
        $warehouse = Warehouse::query()->findOrFail($data['warehouse_id']);
        $user = Auth::user();
        $description = $data['description'] ?? null;
        $service = app(StockService::class);

        try {
            if ($type === StockMovementType::Transfer) {
                $target = Warehouse::query()->findOrFail($data['target_warehouse_id']);
                $movements = $service->transfer($part, $warehouse, $target, $data['quantity'], $user, $description, $this->submissionKey);
                $this->showTransferResult($part, $movements);
            } else {
                $movement = match ($type) {
                    StockMovementType::StockIn => $service->stockIn($part, $data['quantity'], $user, $description, $this->submissionKey, $warehouse),
                    StockMovementType::StockOut => $service->stockOut($part, $data['quantity'], $user, $description, $this->submissionKey, $warehouse),
                    StockMovementType::Return => $service->returnToStock($part, $data['quantity'], $user, $description, $this->submissionKey, $warehouse),
                    StockMovementType::CountAdjustment => $service->adjustCount($part, $data['quantity'], $user, $description, $this->submissionKey, $warehouse),
                };
                $this->showResult($type, $part, $warehouse, $movement);
            }
        } catch (BusinessRuleException $exception) {
            BusinessRuleNotification::send($exception);

            return;
        }

        // Form sıfırlanır: yeni anahtar; tür, ürün ve depolar korunur, miktar ve açıklama temizlenir.
        $this->submissionKey = (string) Str::uuid();
        $this->form->fill([
            'type' => $type->value,
            'part_id' => $part->getKey(),
            'warehouse_id' => $warehouse->getKey(),
            'target_warehouse_id' => $data['target_warehouse_id'] ?? null,
        ]);
    }

    /** @param  EloquentCollection<int, StockMovement>  $movements  [çıkış, giriş] */
    private function showTransferResult(Part $part, EloquentCollection $movements): void
    {
        $unit = $part->unit->name;
        $movements->load('warehouse');
        [$out, $in] = [$movements->first(fn (StockMovement $m) => Quantity::compare((string) $m->quantity, '0') < 0), $movements->first(fn (StockMovement $m) => Quantity::compare((string) $m->quantity, '0') > 0)];

        $title = 'Transfer kaydedildi';
        $body = sprintf(
            '%s %s: %s, %s → %s. Yeni bakiyeler: %s %s, %s %s',
            $part->code,
            $part->name,
            Quantity::format($in->quantity, $unit),
            $out->warehouse->name,
            $in->warehouse->name,
            $out->warehouse->name,
            Quantity::format($out->balance_after, $unit),
            $in->warehouse->name,
            Quantity::format($in->balance_after, $unit),
        );

        $this->lastResult = ['title' => $title, 'body' => $body, 'status' => 'success'];

        if ($out->wasRecentlyCreated) {
            Notification::make()->success()->title($title)->body($body)->send();
        }
    }

    private function showResult(StockMovementType $type, Part $part, Warehouse $warehouse, ?StockMovement $movement): void
    {
        $unit = $part->unit->name;
        $partLabel = "{$part->code} {$part->name} ({$warehouse->name})";

        if ($movement === null) {
            // Sayımda fark yok.
            $balance = (string) (StockBalance::query()->where('part_id', $part->getKey())->where('warehouse_id', $warehouse->getKey())->value('quantity') ?? '0');
            $title = 'Bakiye zaten doğru, hareket oluşturulmadı';
            $body = "{$partLabel}. Bakiye: ".Quantity::format($balance, $unit);
            $status = 'info';
        } else {
            $title = "{$type->label()} kaydedildi";
            $body = sprintf(
                '%s: %s. Yeni bakiye: %s',
                $partLabel,
                Quantity::format($movement->quantity, $unit, signed: true),
                Quantity::format($movement->balance_after, $unit),
            );
            $status = 'success';
        }

        $this->lastResult = ['title' => $title, 'body' => $body, 'status' => $status];

        // Aynı anahtarlı eşzamanlı istek mevcut hareketi alır: aynı sonuç gösterilir ama bildirim
        // tekrarlanmaz (bildirim ilk isteğin yanıtında gönderildi).
        if ($movement === null || $movement->wasRecentlyCreated) {
            Notification::make()->status($status)->title($title)->body($body)->send();
        }
    }

    /** @return array<int, string> */
    private function searchParts(string $search, ?string $type): array
    {
        $search = trim($search);

        return Part::query()
            // Mamul stoğu elle değiştirilemez (yalnızca üretim ve sevk; mamul transferi motor seçimiyle).
            ->where('type', '!=', PartType::FinishedGood->value)
            // Pasif ürüne yalnızca sayım yapılabilir.
            ->when($type !== StockMovementType::CountAdjustment->value, fn (Builder $query) => $query->where('is_active', true))
            ->where(fn (Builder $query) => $query
                ->where('code', 'ilike', "%{$search}%")
                ->orWhere('name', 'ilike', "%{$search}%")
                ->orWhere('barcode', $search))
            ->orderBy('code')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (Part $part): array => [$part->getKey() => $this->partLabel($part)])
            ->all();
    }

    private function partLabel(Part $part): string
    {
        return "{$part->code} — {$part->name}".($part->is_active ? '' : ' (pasif)');
    }

    /**
     * Seçilebilir depolar. Pasif depoda yalnızca sayım ve (kaynak olarak) transfer yapılır.
     *
     * @return array<int, string>
     */
    private function warehouseOptions(?string $type, bool $source): array
    {
        $inactiveAllowed = $type === StockMovementType::CountAdjustment->value
            || ($source && $type === StockMovementType::Transfer->value);

        return Warehouse::query()
            ->when(! $inactiveAllowed, fn (Builder $query) => $query->where('is_active', true))
            ->orderBy('code')
            ->get()
            ->mapWithKeys(fn (Warehouse $w): array => [$w->getKey() => $w->name.($w->is_active ? '' : ' (pasif)')])
            ->all();
    }

    private function balanceText(mixed $partId, mixed $warehouseId, bool $withTotal = true): ?string
    {
        if (blank($partId) || blank($warehouseId) || ! ($part = Part::query()->with(['unit', 'stockBalances'])->find($partId))) {
            return null;
        }

        $balance = $part->balanceIn((int) $warehouseId);
        $text = 'Bu depodaki bakiye: '.Quantity::format($part->quantityIn((int) $warehouseId), $part->unit->name);

        if ($balance?->isCriticalAt()) {
            $text .= ' (kritik seviyede)';
        }

        if ($withTotal && $part->stockBalances->count() > 1) {
            $text .= ' · Tüm depolar: '.Quantity::format($part->totalStock(), $part->unit->name);
        }

        return $text;
    }

    private function unitName(mixed $partId): ?string
    {
        return blank($partId) ? null : Part::query()->with('unit')->find($partId)?->unit->name;
    }
}
