<?php

namespace App\Filament\Pages;

use App\Enums\MotorPhase;
use App\Enums\PartType;
use App\Enums\Permission;
use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\MotorModels\MotorModelResource;
use App\Filament\Support\BusinessRuleNotification;
use App\Filament\Support\PartOption;
use App\Models\BomTemplate;
use App\Models\BomTemplateLine;
use App\Models\Part;
use App\Models\PartSubtype;
use App\Services\BomTemplateService;
use App\Services\MotorBuilderService;
use App\Support\Quantity;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Motor oluştur: motor tipi (motor şablonu) seçilir, montaj çiziminde numaralı her parça için kullanılacak ürün
 * (ör. rulman çeşidi adıyla) ve miktarı girilir; birim ürünün biriminden gelir (adet, gram, kg). Kaydedince motor
 * modeli, mamul kartı ve reçetesi tek işlemde oluşur. Tüm roller kullanır (motor_models.build); mevcut modelin
 * ve reçetenin değiştirilmesi motor modeli yönetme yetkisindedir.
 */
class MotorBuilder extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static string|UnitEnum|null $navigationGroup = 'Üretim';

    protected static ?int $navigationSort = 14;

    protected static ?string $navigationLabel = 'Motor oluştur';

    protected static ?string $title = 'Motor oluştur';

    protected static ?string $slug = 'motor-olustur';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** @var Collection<int, Part>|null İstek boyunca seçili ürünler (görsel ve birim için tek sorgu). */
    protected ?Collection $selectedParts = null;

    public static function canAccess(): bool
    {
        return Auth::user()?->can(Permission::MotorModelsBuild) ?? false;
    }

    public function mount(): void
    {
        $this->form->fill(['mounting_type' => 'B3', 'rows' => []]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Motor tipi ve bilgileri')
                    ->columns(['default' => 1, 'md' => 3])
                    ->schema([
                        Select::make('template_id')
                            ->label('Motor tipi')
                            ->required()
                            ->live()
                            ->options(fn (): array => BomTemplate::query()->where('is_active', true)->where('product_type', PartType::FinishedGood->value)->orderBy('id')->pluck('name', 'id')->all())
                            ->afterStateUpdated(fn ($state, Set $set) => $this->fillRows($state, $set))
                            ->helperText('Tipler: Üretim › Reçete şablonları (yeni tip oradan eklenir).'),
                        TextInput::make('code')->label('Motor kodu')->required()->maxLength(50)->placeholder('ör. M71-037-4'),
                        TextInput::make('name')->label('Motor adı')->required()->maxLength(255)->placeholder('ör. 0,37 kW 1500 d/dk 71 trifaze'),
                        Select::make('subtype_id')->label('Alt tür')->placeholder('Alt tür yok')
                            ->options(fn (): array => PartSubtype::query()->where('type', PartType::FinishedGood->value)->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all()),
                        Select::make('phase')->label('Faz')->options(MotorPhase::class),
                        TextInput::make('power_kw')->label('Güç')->suffix('kW')->inputMode('decimal')->rule($this->positiveRule('Geçerli bir güç girin (ör. 0,37).')),
                        TextInput::make('speed_rpm')->label('Devir')->suffix('d/dk')->integer()->minValue(1),
                        TextInput::make('pole_count')->label('Kutup sayısı')->integer()->minValue(1)->maxValue(32767),
                        TextInput::make('voltage')->label('Gerilim')->maxLength(50)->placeholder('ör. 230/400 V'),
                        TextInput::make('frame_size')->label('Gövde ölçüsü')->maxLength(50)->placeholder('ör. 71'),
                        TextInput::make('mounting_type')->label('Montaj şekli')->maxLength(50),
                    ]),
                Section::make('Bileşenler')
                    ->description('Her parça için kullanılacak ürünü seçin (adıyla arayın; ör. rulman 6202) ve miktarı girin. Birim ürünün biriminden gelir. Motorda olmayan parçayı boş bırakın. Aynı ürünü seçen satırlar (ör. ön ve arka rulman) tek satırda toplanır.')
                    ->visible(fn (Get $get): bool => filled($get('template_id')))
                    ->schema([
                        Grid::make(['default' => 1, 'lg' => 5])->schema([
                            View::make('filament.pages.motor-builder.drawing')
                                ->viewData(fn (Get $get): array => $this->drawingData($get('template_id'), $get('rows') ?? []))
                                ->columnSpan(['default' => 1, 'lg' => 2]),
                            Group::make()
                                ->schema(fn (Get $get): array => $this->rows($get('template_id')))
                                ->columnSpan(['default' => 1, 'lg' => 3]),
                        ]),
                    ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedSchema::make('form'),
            Actions::make([
                Action::make('build')
                    ->label('Motoru oluştur')
                    ->icon(Heroicon::OutlinedCheck)
                    ->action(fn () => $this->build()),
            ])->key('builder-actions'),
        ]);
    }

    public function build(): void
    {
        $data = $this->form->getState();
        $template = BomTemplate::query()->findOrFail($data['template_id']);
        $model = collect($data)->only(['code', 'name', 'subtype_id', 'phase', 'power_kw', 'speed_rpm', 'pole_count', 'voltage', 'frame_size', 'mounting_type'])->all();

        try {
            $result = app(MotorBuilderService::class)->build($template, $model, $data['rows'] ?? []);
        } catch (BusinessRuleException $exception) {
            BusinessRuleNotification::send($exception);

            return;
        }

        $created = $result['model'];
        Notification::make()->success()
            ->title("Motor oluşturuldu: {$created->code}")
            ->body("{$created->name} — reçetede {$result['added']} bileşen.")
            ->send();

        if (Auth::user()->can('view', $created)) {
            $this->redirect(MotorModelResource::getUrl('view', ['record' => $created]));

            return;
        }

        $this->form->fill(['mounting_type' => 'B3', 'rows' => []]);
    }

    private function fillRows(mixed $templateId, Set $set): void
    {
        $rows = [];

        foreach (BomTemplate::query()->find($templateId)?->lines ?? [] as $line) {
            $rows[$line->getKey()] = [
                'part_id' => null,
                'quantity_per_unit' => $line->quantity_per_unit === null ? null : Quantity::toInput((string) $line->quantity_per_unit),
            ];
        }

        $set('rows', $rows);
    }

    /** @return array<int, Grid> */
    private function rows(mixed $templateId): array
    {
        $template = filled($templateId) ? BomTemplate::query()->find($templateId) : null;

        if ($template === null) {
            return [];
        }

        $service = app(BomTemplateService::class);

        return $template->lines()->where('kind', BomTemplateLine::KIND_COMPONENT)->with('subtype')->get()
            ->map(function (BomTemplateLine $line) use ($service): Grid {
                $key = "rows.{$line->getKey()}";
                $number = $line->drawingLabel();

                return Grid::make(['default' => 6, 'md' => 12])
                    ->extraAttributes(['id' => "motor-line-{$line->getKey()}", 'style' => 'scroll-margin-top: 6rem'])
                    ->schema([
                        View::make('filament.pages.motor-builder.thumb')
                            ->viewData(fn (Get $get): array => ['number' => $number, 'part' => $this->part($get("{$key}.part_id"))])
                            ->columnSpan(['default' => 1, 'md' => 1]),
                        Select::make("{$key}.part_id")
                            ->label(($number !== null ? "{$number} · " : '').$line->label)
                            ->searchable()
                            ->live()
                            ->placeholder('Yok (kullanılmıyor)')
                            ->allowHtml()
                            ->options(fn (): array => PartOption::options($service->candidateParts($line), BomTemplateService::candidateLabel(...)))
                            ->getSearchResultsUsing(fn (string $search): array => PartOption::options($service->candidateParts($line, search: trim($search)), BomTemplateService::candidateLabel(...)))
                            ->getOptionLabelUsing(fn ($value): ?string => ($part = $this->part($value)) ? PartOption::html($part, BomTemplateService::candidateLabel($part)) : null)
                            ->helperText(fn (Get $get): ?string => $this->part($get("{$key}.part_id"))?->description
                                ?? ($line->subtype ? "{$line->component_type->label()} › {$line->subtype->name}" : $line->component_type->label()))
                            ->columnSpan(['default' => 5, 'md' => 7]),
                        TextInput::make("{$key}.quantity_per_unit")
                            ->label('Miktar')
                            ->inputMode('decimal')
                            ->placeholder('Girin')
                            ->required(fn (Get $get): bool => filled($get("{$key}.part_id")))
                            ->rule($this->positiveRule('Sıfırdan büyük bir miktar girin (ör. 2,5).'))
                            ->columnSpan(['default' => 3, 'md' => 2]),
                        TextEntry::make("{$key}.unit")
                            ->label('Birim')
                            ->state(fn (Get $get): string => $this->part($get("{$key}.part_id"))?->unit->name ?? '—')
                            ->columnSpan(['default' => 3, 'md' => 2]),
                    ]);
            })->all();
    }

    /**
     * Çizim ve numara işaretleri: şablonda olan numara, satırlarından birine ürün seçildiyse "seçildi".
     *
     * @return array<string, mixed>
     */
    private function drawingData(mixed $templateId, array $rows): array
    {
        $template = filled($templateId) ? BomTemplate::query()->find($templateId) : null;
        $drawing = $template?->drawingConfig();

        if ($drawing === null) {
            return ['drawing' => null];
        }

        $markers = [];

        foreach ($template->lines as $line) {
            if ($line->drawing_number === null) {
                continue;
            }

            $number = $line->drawing_number;
            $markers[$number] ??= ['label' => $line->drawingLabel(), 'lines' => [], 'selected' => false, 'target' => "motor-line-{$line->getKey()}"];
            $markers[$number]['lines'][] = $line->label;
            $markers[$number]['selected'] = $markers[$number]['selected'] || filled($rows[$line->getKey()]['part_id'] ?? null);
        }

        $points = [];

        foreach ($markers as $number => $marker) {
            foreach ($drawing['markers'][$number] ?? [] as [$x, $y]) {
                $points[] = [...$marker, 'left' => round($x / $drawing['width'] * 100, 2), 'top' => round($y / $drawing['height'] * 100, 2)];
            }
        }

        return ['drawing' => $drawing, 'points' => $points];
    }

    private function part(mixed $id): ?Part
    {
        if (blank($id)) {
            return null;
        }

        if ($this->selectedParts === null || ! $this->selectedParts->has((int) $id)) {
            $ids = collect($this->data['rows'] ?? [])->pluck('part_id')->filter()->map(fn ($v) => (int) $v)->push((int) $id)->unique();
            $this->selectedParts = Part::query()->with('unit')->whereKey($ids)->get()->keyBy('id');
        }

        return $this->selectedParts->get((int) $id);
    }

    private function positiveRule(string $message): Closure
    {
        return fn (): Closure => function (string $attribute, mixed $value, Closure $fail) use ($message): void {
            if (filled($value) && (! Quantity::isValid($value) || Quantity::compare(Quantity::parse($value), '0') <= 0)) {
                $fail($message);
            }
        };
    }
}
