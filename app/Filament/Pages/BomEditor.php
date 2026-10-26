<?php

namespace App\Filament\Pages;

use App\Enums\PartType;
use App\Enums\Permission;
use App\Exceptions\BusinessRuleException;
use App\Filament\Support\BomLineForm;
use App\Filament\Support\BusinessRuleNotification;
use App\Models\BomCoProduct;
use App\Models\BomItem;
use App\Models\BomTemplate;
use App\Models\BomTemplateLine;
use App\Models\Part;
use App\Services\BomService;
use App\Services\BomTemplateService;
use App\Services\CatalogImportService;
use App\Support\Quantity;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Reçete hazırlama ekranı: ürün (yarı mamul veya mamul) seçilir; reçete satırları (fire oran veya brüt/fire miktarıyla,
 * isteğe bağlı fire kartı), eş ürünler, başka reçeteden kopyalama, çok seviyeli ağaç ve "X adet için ihtiyaç".
 * Görüntüleme motor modeli görme, değiştirme motor modeli yönetme yetkisiyle (BomItemPolicy ile aynı).
 */
class BomEditor extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Üretim';

    protected static ?int $navigationSort = 15;

    protected static ?string $navigationLabel = 'Reçeteler';

    protected static ?string $title = 'Reçeteler';

    protected static ?string $slug = 'receteler';

    #[Url(as: 'part')]
    public ?int $partId = null;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Auth::user()?->can(Permission::MotorModelsView) ?? false;
    }

    public function mount(): void
    {
        $part = $this->partId !== null ? Part::query()->whereKey($this->partId)->whereIn('type', [PartType::SemiFinished->value, PartType::FinishedGood->value])->first() : null;
        $this->partId = $part?->getKey();

        $this->form->fill(['part_id' => $this->partId, 'quantity' => '1']);
    }

    public function canManage(): bool
    {
        return Auth::user()?->can(Permission::MotorModelsManage) ?? false;
    }

    public function part(): ?Part
    {
        return $this->partId === null ? null : Part::query()->with(['unit', 'subtype', 'coProducts.coPart.unit', 'coProductOf.parent', 'motorModel'])->find($this->partId);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->columns(['default' => 1, 'md' => 3])
            ->components([
                Select::make('part_id')
                    ->label('Ürün')
                    ->placeholder('Yarı mamul veya mamul arayın (kod, ad)')
                    ->searchable()
                    ->live()
                    ->columnSpan(['md' => 2])
                    ->getSearchResultsUsing(fn (string $search): array => $this->searchProducts($search))
                    ->getOptionLabelUsing(fn ($value): ?string => ($part = Part::query()->find($value)) ? $this->productLabel($part) : null)
                    ->afterStateUpdated(function ($state): void {
                        $this->partId = filled($state) ? (int) $state : null;
                        $this->resetTable();
                    }),
                TextInput::make('quantity')
                    ->label('İhtiyaç hesabı için adet')
                    ->inputMode('decimal')
                    ->live(debounce: 500)
                    ->helperText('Ağaç ve toplam ihtiyaç bu adede göre hesaplanır (fire dahil, stok hariç).'),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->schema([EmbeddedSchema::make('form')]),
            View::make('filament.pages.bom-editor.summary')->visible(fn (): bool => $this->partId !== null),
            Section::make('Reçete satırları')
                ->description('Bir birim için gereken bileşenler. Fire oranla veya brüt/fire miktarıyla girilir.')
                ->visible(fn (): bool => $this->partId !== null && $this->part()?->coProductOf === null)
                ->schema([EmbeddedTable::make()]),
            View::make('filament.pages.bom-editor.tree')->visible(fn (): bool => $this->partId !== null),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => BomItem::query()->with(['component.unit', 'scrapPart'])->where('parent_part_id', $this->partId ?? 0))
            ->defaultSort('id')
            ->paginated(false)
            ->columns(BomLineForm::columns())
            ->emptyStateHeading('Reçete boş')
            ->emptyStateDescription(fn (): ?string => $this->canManage() ? '"Bileşen ekle" ile satır ekleyin veya başka bir reçeteden kopyalayın.' : null)
            ->headerActions([
                Action::make('addLine')
                    ->label('Bileşen ekle')
                    ->icon(Heroicon::OutlinedPlus)
                    ->visible(fn (): bool => $this->canManage())
                    ->schema(fn (): array => BomLineForm::fields(fn () => $this->part(), creating: true))
                    ->action(fn (array $data, Action $action) => $this->guard($action, fn () => BomLineForm::save($this->part(), $data))),
                $this->templateAction(),
                Action::make('paste')
                    ->label("Excel'den yapıştır")
                    ->icon(Heroicon::OutlinedClipboardDocument)
                    ->color('gray')
                    ->visible(fn (): bool => $this->canManage())
                    ->modalDescription("Excel'de satırları seçip kopyalayın ve aşağıya yapıştırın. Sütun sırası: bileşen kodu, net miktar, fire oranı (%), brüt miktar, fire miktarı, fire kartı kodu (kullanmadığınızı boş bırakın). Tek satır bile hatalıysa hiçbiri eklenmez.")
                    ->schema([
                        Textarea::make('text')->label('Satırlar')->required()->rows(10)->placeholder('HM-SAC-050-110			6,61	2,88	HM-HURDA-SAC
KOM-RUL-6202	2'),
                        Toggle::make('update')->label('Reçetede olan bileşenleri güncelle'),
                    ])
                    ->action(function (array $data, Action $action): void {
                        try {
                            $result = app(CatalogImportService::class)->pasteBom($this->part(), $data['text'], Auth::user(), (bool) ($data['update'] ?? false));
                        } catch (BusinessRuleException $exception) {
                            BusinessRuleNotification::send($exception);
                            $action->halt();
                        }

                        if (! $result->committed) {
                            $errors = collect($result->sheets)->flatten(1)->where('status', 'error')
                                ->map(fn (array $r) => "Satır {$r['row']} ({$r['key']}): {$r['message']}")->take(10)->implode("\n");
                            Notification::make()->danger()->title('Hiçbir satır eklenmedi')->body($errors)->persistent()->send();
                            $action->halt();
                        }

                        $counts = $result->counts();
                        Notification::make()->success()
                            ->title(sprintf('%d satır eklendi', $counts['created'] ?? 0))
                            ->body(collect(['updated' => 'güncellendi', 'skipped' => 'zaten vardı, atlandı'])->filter(fn ($l, $s) => ($counts[$s] ?? 0) > 0)->map(fn ($l, $s) => "{$counts[$s]} satır {$l}")->implode(', ') ?: null)
                            ->send();
                    }),
                Action::make('copy')
                    ->label('Başka reçeteden kopyala')
                    ->icon(Heroicon::OutlinedDocumentDuplicate)
                    ->color('gray')
                    ->visible(fn (): bool => $this->canManage())
                    ->modalDescription('Kaynak reçetenin satırları eklenir; bu reçetede zaten olan bileşenler atlanır. Eş ürünler kopyalanmaz.')
                    ->schema([
                        Select::make('source_id')
                            ->label('Kaynak ürün')
                            ->required()
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => $this->searchProducts($search, onlyWithBom: true))
                            ->getOptionLabelUsing(fn ($value): ?string => ($part = Part::query()->find($value)) ? $this->productLabel($part) : null),
                    ])
                    ->action(function (array $data, Action $action): void {
                        $count = $this->guard($action, fn () => app(BomService::class)->copy(Part::query()->findOrFail($data['source_id']), $this->part()));
                        Notification::make()->success()->title("{$count} satır kopyalandı")->send();
                    }),
            ])
            ->recordActions([
                Action::make('editLine')
                    ->label('Düzenle')
                    ->icon('heroicon-m-pencil-square')
                    ->visible(fn (): bool => $this->canManage())
                    ->fillForm(fn (BomItem $record): array => BomLineForm::fill($record))
                    ->schema(fn (): array => BomLineForm::fields(fn () => $this->part(), creating: false))
                    ->action(fn (BomItem $record, array $data, Action $action) => $this->guard($action, fn () => BomLineForm::save($this->part(), $data, $record))),
                Action::make('removeLine')
                    ->label('Kaldır')
                    ->icon('heroicon-m-trash')
                    ->color('danger')
                    ->visible(fn (): bool => $this->canManage())
                    ->requiresConfirmation()
                    ->modalDescription('Açık iş emirleri kendi malzeme listesini kullandığı için etkilenmez.')
                    ->action(function (BomItem $record, Action $action): void {
                        $this->guard($action, fn () => app(BomService::class)->remove($record));
                        Notification::make()->success()->title('Bileşen kaldırıldı')->send();
                    }),
            ]);
    }

    /**
     * Şablondan başlat: şablon seçilir, her satıra uyan ürün (tür ve alt türe göre süzülü) seçilir; miktar ve fire
     * şablondan dolu gelir. Ürün seçilmeyen satır atlanır; herhangi bir satır reddedilirse hiçbiri eklenmez.
     */
    private function templateAction(): Action
    {
        return Action::make('fromTemplate')
            ->label('Şablondan başlat')
            ->icon(Heroicon::OutlinedClipboardDocumentList)
            ->visible(fn (): bool => $this->canManage() && $this->part()?->coProductOf === null)
            ->modalWidth('5xl')
            ->modalSubmitActionLabel('Reçeteye ekle')
            ->modalDescription('Her satıra kullanılacak ürünü seçin; kullanılmayacak satırı boş bırakın. Reçetede zaten olan bileşen atlanır.')
            ->schema([
                Select::make('template_id')
                    ->label('Şablon')
                    ->required()
                    ->live()
                    ->options(fn (): array => BomTemplate::query()->where('is_active', true)->where('product_type', $this->part()?->type->value)->orderBy('name')->pluck('name', 'id')->all())
                    ->afterStateUpdated(function ($state, Set $set): void {
                        $rows = [];
                        foreach (BomTemplate::query()->find($state)?->lines ?? [] as $line) {
                            $input = fn ($v): ?string => $v === null ? null : Quantity::toInput((string) $v);
                            $rows[$line->getKey()] = [
                                'part_id' => null,
                                'quantity_per_unit' => $input($line->quantity_per_unit),
                                'scrap_rate' => $input($line->scrap_rate),
                                'gross_per_unit' => $input($line->gross_per_unit),
                                'scrap_quantity_per_unit' => $input($line->scrap_quantity_per_unit),
                                'scrap_part_id' => null,
                            ];
                        }
                        $set('rows', $rows);
                    })
                    ->helperText(fn (): string => 'Yalnızca '.mb_strtolower($this->part()?->type->label() ?? '').' şablonları. Şablonlar: Üretim › Reçete şablonları.'),
                Group::make()->schema(fn (Get $get): array => $this->templateRows($get('template_id'))),
            ])
            ->action(function (array $data, Action $action): void {
                $template = BomTemplate::query()->findOrFail($data['template_id']);
                $result = $this->guard($action, fn () => app(BomTemplateService::class)->apply($template, $this->part(), $data['rows'] ?? []));
                Notification::make()->success()
                    ->title("{$result['added']} satır eklendi")
                    ->body($result['skipped'] > 0 ? "{$result['skipped']} satır boş bırakıldı veya reçetede zaten vardı." : null)
                    ->send();
            });
    }

    /** @return array<int, mixed> */
    private function templateRows(mixed $templateId): array
    {
        $template = filled($templateId) ? BomTemplate::query()->find($templateId) : null;
        $parent = $this->part();

        if ($template === null || $parent === null) {
            return [];
        }

        return $template->lines()->with('subtype')->get()->map(function (BomTemplateLine $line) use ($parent): Fieldset {
            $key = "rows.{$line->getKey()}";
            $title = $line->label.($line->isCoProduct() ? ' — eş ürün' : '').' · '.$line->component_type->label().($line->subtype ? ' › '.$line->subtype->name : '');
            $service = app(BomTemplateService::class);

            $fields = [
                Select::make("{$key}.part_id")
                    ->label('Ürün')
                    ->searchable()
                    ->placeholder('Kullanılmayacaksa boş bırakın')
                    ->options(fn (): array => $service->candidates($line, $parent))
                    ->getSearchResultsUsing(fn (string $search): array => $service->candidates($line, $parent, trim($search)))
                    ->columnSpan(2),
            ];

            if ($line->usesScrapQuantity()) {
                $fields[] = TextInput::make("{$key}.gross_per_unit")->label('Brüt')->inputMode('decimal');
                $fields[] = TextInput::make("{$key}.scrap_quantity_per_unit")->label('Fire miktarı')->inputMode('decimal');
                $fields[] = Select::make("{$key}.scrap_part_id")->label('Fire kartı (hurda)')->searchable()->placeholder('Yok')
                    ->getSearchResultsUsing(fn (string $search): array => Part::query()->where('is_active', true)->where('type', '!=', PartType::FinishedGood->value)
                        ->where(fn ($q) => $q->where('code', 'ilike', "%{$search}%")->orWhere('name', 'ilike', "%{$search}%"))
                        ->orderBy('code')->limit(50)->get()->mapWithKeys(fn (Part $p) => [$p->getKey() => "{$p->code} — {$p->name}"])->all())
                    ->getOptionLabelUsing(fn ($value): ?string => ($p = Part::query()->find($value)) ? "{$p->code} — {$p->name}" : null);
            } else {
                $fields[] = TextInput::make("{$key}.quantity_per_unit")->label($line->isCoProduct() ? 'Birim başına' : 'Net miktar')->inputMode('decimal')
                    ->placeholder($line->quantity_per_unit === null ? 'Girin' : null);
                if (! $line->isCoProduct()) {
                    $fields[] = TextInput::make("{$key}.scrap_rate")->label('Fire (%)')->inputMode('decimal');
                }
            }

            return Fieldset::make($title)->columns(5)->schema($fields);
        })->all();
    }

    public function addCoProductAction(): Action
    {
        return Action::make('addCoProduct')
            ->label('Eş ürün ekle')
            ->icon(Heroicon::OutlinedPlus)
            ->size('sm')
            ->visible(fn (): bool => $this->canManage() && $this->part()?->type === PartType::SemiFinished && $this->part()?->coProductOf === null)
            ->modalDescription('Bu ürün bir birim üretilirken aynı işlemde çıkan başka yarı mamul (ör. aynı kalıpta basılan rotor paketi). Eş ürünün kendi reçetesi olmaz.')
            ->schema([
                Select::make('co_part_id')
                    ->label('Eş ürün')
                    ->required()
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => Part::query()
                        ->where('type', PartType::SemiFinished->value)
                        ->where('is_active', true)
                        ->whereKeyNot($this->partId)
                        ->whereDoesntHave('bomItems')
                        ->whereDoesntHave('coProductOf')
                        ->where(fn (Builder $q) => $q->where('code', 'ilike', "%{$search}%")->orWhere('name', 'ilike', "%{$search}%"))
                        ->orderBy('code')->limit(50)->get()
                        ->mapWithKeys(fn (Part $p) => [$p->getKey() => "{$p->code} — {$p->name}"])->all())
                    ->getOptionLabelUsing(fn ($value): ?string => ($p = Part::query()->find($value)) ? "{$p->code} — {$p->name}" : null)
                    ->helperText('Reçetesi olmayan aktif yarı mamuller.'),
                TextInput::make('quantity_per_unit')->label('Birim başına')->required()->default('1')->inputMode('decimal')
                    ->rule($this->positiveRule()),
            ])
            ->action(function (array $data, Action $action): void {
                $this->guard($action, fn () => app(BomService::class)->addCoProduct($this->part(), (int) $data['co_part_id'], $data['quantity_per_unit']));
                Notification::make()->success()->title('Eş ürün eklendi')->send();
            });
    }

    public function editCoProductAction(): Action
    {
        return Action::make('editCoProduct')
            ->label('Düzenle')
            ->icon('heroicon-m-pencil-square')
            ->link()
            ->visible(fn (): bool => $this->canManage())
            ->fillForm(fn (array $arguments): array => ($coProduct = $this->coProduct($arguments)) ? [
                'quantity_per_unit' => Quantity::toInput((string) $coProduct->quantity_per_unit),
                'lock_version' => $coProduct->lock_version,
            ] : [])
            ->schema([
                TextInput::make('quantity_per_unit')->label('Birim başına')->required()->inputMode('decimal')->rule($this->positiveRule()),
                Hidden::make('lock_version'),
            ])
            ->action(function (array $data, array $arguments, Action $action): void {
                $this->guard($action, fn () => app(BomService::class)->updateCoProduct($this->coProduct($arguments), $data['quantity_per_unit'], (int) $data['lock_version']));
                Notification::make()->success()->title('Eş ürün kaydedildi')->send();
            });
    }

    public function removeCoProductAction(): Action
    {
        return Action::make('removeCoProduct')
            ->label('Kaldır')
            ->icon('heroicon-m-trash')
            ->color('danger')
            ->link()
            ->visible(fn (): bool => $this->canManage())
            ->requiresConfirmation()
            ->modalDescription('Açık iş emirleri kendi çıktı listesini kullandığı için etkilenmez.')
            ->action(function (array $arguments, Action $action): void {
                $this->guard($action, fn () => app(BomService::class)->removeCoProduct($this->coProduct($arguments)));
                Notification::make()->success()->title('Eş ürün kaldırıldı')->send();
            });
    }

    /** @return list<array<string, mixed>> */
    public function treeNodes(): array
    {
        $part = $this->part();

        return $part === null ? [] : app(BomService::class)->tree($part, $this->quantity());
    }

    /** @return list<array{part: Part, total: string}> */
    public function requirementRows(): array
    {
        $part = $this->part();

        if ($part === null) {
            return [];
        }

        $rows = app(BomService::class)->requirements($part, $this->quantity());
        $ids = array_map(fn (array $row) => $row['part']->getKey(), $rows);
        $stocked = Part::query()->with('stockBalances')->findMany($ids)->keyBy('id');

        return array_map(fn (array $row) => [...$row, 'stock' => $stocked[$row['part']->getKey()]->totalStock()], $rows);
    }

    public function quantity(): string
    {
        $value = $this->data['quantity'] ?? '1';

        return Quantity::isValid($value) && Quantity::compare(Quantity::parse($value), '0') > 0 ? Quantity::parse($value) : '1.000';
    }

    private function coProduct(array $arguments): ?BomCoProduct
    {
        return BomCoProduct::query()->where('parent_part_id', $this->partId)->find($arguments['coProduct'] ?? 0);
    }

    /** @return array<int, string> */
    private function searchProducts(string $search, bool $onlyWithBom = false): array
    {
        $search = trim($search);

        return Part::query()
            ->with('coProductOf')
            ->withCount('bomItems')
            ->whereIn('type', [PartType::SemiFinished->value, PartType::FinishedGood->value])
            ->when($onlyWithBom, fn (Builder $q) => $q->whereHas('bomItems')->whereKeyNot($this->partId ?? 0))
            ->where(fn (Builder $q) => $q->where('code', 'ilike', "%{$search}%")->orWhere('name', 'ilike', "%{$search}%"))
            ->orderBy('code')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (Part $part) => [$part->getKey() => $this->productLabel($part)])
            ->all();
    }

    private function productLabel(Part $part): string
    {
        $suffix = match (true) {
            $part->coProductOf !== null => ' (eş ürün)',
            ($part->bom_items_count ?? $part->bomItems()->count()) === 0 => ' (reçete yok)',
            default => '',
        };

        return "{$part->code} — {$part->name}{$suffix}".($part->is_active ? '' : ' (pasif)');
    }

    private function positiveRule(): Closure
    {
        return fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
            if (! Quantity::isValid($value) || Quantity::compare(Quantity::parse($value), '0') <= 0) {
                $fail('Sıfırdan büyük geçerli bir miktar girin.');
            }
        };
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function guard(Action $action, Closure $callback): mixed
    {
        try {
            return $callback();
        } catch (BusinessRuleException $exception) {
            BusinessRuleNotification::send($exception);
            $action->halt();
        }
    }
}
