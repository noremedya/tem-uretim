<?php

namespace App\Filament\Pages;

use App\Enums\Permission;
use App\Enums\StockMovementType;
use App\Exceptions\BusinessRuleException;
use App\Filament\Support\BusinessRuleNotification;
use App\Models\Part;
use App\Models\StockBalance;
use App\Models\StockMovement;
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
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use UnitEnum;

/**
 * Stok giriş / çıkış / sayım / iade formu.
 *
 * Çift gönderim koruması yalnızca gönderim anahtarına dayanır:
 * - İstek sürerken kaydet butonu pasiftir (Filament, wire:loading).
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

        $part = request()->integer('part') ?: null;

        $this->form->fill([
            'type' => StockMovementType::StockIn->value,
            'part_id' => $part !== null && Part::query()->whereKey($part)->exists() ? $part : null,
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
                            ])
                            ->inline()
                            ->required()
                            ->live()
                            ->columnSpanFull(),
                        Select::make('part_id')
                            ->label('Parça')
                            ->placeholder('Kod, ad veya barkod ile arayın')
                            ->searchable()
                            ->required()
                            ->live()
                            ->getSearchResultsUsing(fn (string $search, Get $get): array => $this->searchParts($search, $get('type')))
                            ->getOptionLabelUsing(fn ($value): ?string => ($part = Part::query()->find($value)) ? $this->partLabel($part) : null)
                            ->helperText(fn (Get $get): ?string => $this->balanceText($get('part_id')))
                            ->columnSpanFull(),
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
        $user = Auth::user();
        $description = $data['description'] ?? null;
        $service = app(StockService::class);

        try {
            $movement = match ($type) {
                StockMovementType::StockIn => $service->stockIn($part, $data['quantity'], $user, $description, $this->submissionKey),
                StockMovementType::StockOut => $service->stockOut($part, $data['quantity'], $user, $description, $this->submissionKey),
                StockMovementType::Return => $service->returnToStock($part, $data['quantity'], $user, $description, $this->submissionKey),
                StockMovementType::CountAdjustment => $service->adjustCount($part, $data['quantity'], $user, $description, $this->submissionKey),
            };
        } catch (BusinessRuleException $exception) {
            BusinessRuleNotification::send($exception);

            return;
        }

        $this->showResult($type, $part, $movement);

        // Form sıfırlanır: yeni anahtar; tür ve parça korunur, miktar ve açıklama temizlenir.
        $this->submissionKey = (string) Str::uuid();
        $this->form->fill(['type' => $type->value, 'part_id' => $part->getKey()]);
    }

    private function showResult(StockMovementType $type, Part $part, ?StockMovement $movement): void
    {
        $unit = $part->unit->name;
        $partLabel = "{$part->code} {$part->name}";

        if ($movement === null) {
            // Sayımda fark yok.
            $balance = (string) StockBalance::query()->where('part_id', $part->getKey())->value('quantity');
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
            // Pasif parçaya yalnızca sayım yapılabilir.
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

    private function balanceText(mixed $partId): ?string
    {
        if (blank($partId) || ! ($part = Part::query()->with(['unit', 'stockBalance'])->find($partId))) {
            return null;
        }

        $text = 'Güncel bakiye: '.Quantity::format($part->stockBalance?->quantity, $part->unit->name);

        if ($part->isCriticalAt((string) $part->stockBalance?->quantity)) {
            $text .= ' (kritik seviyede)';
        }

        return $text;
    }

    private function unitName(mixed $partId): ?string
    {
        return blank($partId) ? null : Part::query()->with('unit')->find($partId)?->unit->name;
    }
}
