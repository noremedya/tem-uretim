<?php

namespace App\Filament\Pages;

use App\Enums\MotorPhase;
use App\Enums\PartType;
use App\Exceptions\BusinessRuleException;
use App\Filament\Concerns\InteractsWithAssemblyDrawing;
use App\Filament\Resources\MotorDesigns\MotorDesignResource;
use App\Filament\Support\BusinessRuleNotification;
use App\Filament\Support\PartOption;
use App\Models\BomTemplate;
use App\Models\BomTemplateLine;
use App\Models\MotorDesign;
use App\Models\PartSubtype;
use App\Models\Unit;
use App\Services\BomTemplateService;
use App\Services\MotorDesignService;
use App\Support\Quantity;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
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
use Illuminate\Support\Facades\Auth;

/**
 * Bileşen seçerek tasarla ("Motor oluştur" gibi): motor tipi seçilir, montaj çizimindeki her satıra ürün veya kartı
 * olmayan deneme bileşeni (ad + birim) ve miktar girilir; motor görseli eklenebilir. Kaydedince yalnızca tasarım oluşur
 * (stok, motor modeli ve reçete etkilenmez). Tasarımlar listesinden açılır; yetki tasarım oluşturma (yönetici).
 */
class DesignBuilder extends Page
{
    use InteractsWithAssemblyDrawing;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Bileşen seçerek tasarla';

    protected static ?string $slug = 'tasarim-olustur';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return (bool) config('features.motor_designs') && (Auth::user()?->can('create', MotorDesign::class) ?? false);
    }

    public function getBreadcrumbs(): array
    {
        return [MotorDesignResource::getUrl() => 'Tasarımlar', 'Bileşen seçerek tasarla'];
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
                Section::make('Motor tipi ve tasarım bilgileri')
                    ->columns(['default' => 1, 'md' => 3])
                    ->schema([
                        Select::make('template_id')
                            ->label('Motor tipi')
                            ->required()
                            ->live()
                            ->options(fn (): array => BomTemplate::query()->where('is_active', true)->where('product_type', PartType::FinishedGood->value)->orderBy('id')->pluck('name', 'id')->all())
                            ->afterStateUpdated(fn ($state, Set $set) => $this->fillRows($state, $set)),
                        TextInput::make('code')->label('Tasarım kodu')->required()->maxLength(50)->placeholder('ör. TS-71-YENI'),
                        TextInput::make('name')->label('Tasarım adı')->required()->maxLength(150)->placeholder('ör. 71 tip yeni kapak denemesi'),
                        Select::make('subtype_id')->label('Alt tür')->placeholder('Alt tür yok')
                            ->options(fn (): array => PartSubtype::query()->where('type', PartType::FinishedGood->value)->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all()),
                        Select::make('phase')->label('Faz')->options(MotorPhase::class),
                        TextInput::make('power_kw')->label('Güç')->suffix('kW')->inputMode('decimal')->rule($this->positiveRule('Geçerli bir güç girin (ör. 0,37).')),
                        TextInput::make('speed_rpm')->label('Devir')->suffix('d/dk')->integer()->minValue(1),
                        TextInput::make('pole_count')->label('Kutup sayısı')->integer()->minValue(1)->maxValue(32767),
                        TextInput::make('voltage')->label('Gerilim')->maxLength(50)->placeholder('ör. 230/400 V'),
                        TextInput::make('frame_size')->label('Gövde ölçüsü')->maxLength(50),
                        TextInput::make('mounting_type')->label('Montaj şekli')->maxLength(50),
                        self::imageUpload(),
                        Textarea::make('description')->label('Açıklama')->rows(3)->columnSpan(['default' => 1, 'md' => 2]),
                    ]),
                Section::make('Bileşenler')
                    ->description('Her parça için ürün seçin; ürün kartı henüz yoksa deneme bileşeninin adını ve birimini yazın. Motorda olmayan parçayı boş bırakın. Tasarım stoktan düşmez.')
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

    /** Tasarım görseli alanı (oluşturma ve düzenleme formunda aynı kurallar). */
    public static function imageUpload(): FileUpload
    {
        return FileUpload::make('image_path')
            ->label('Motor görseli')
            ->image()
            ->disk(MotorDesignService::IMAGE_DISK)
            ->directory(MotorDesignService::IMAGE_DIRECTORY)
            ->visibility('private')
            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
            ->maxSize(5120)
            ->imageResizeMode('contain')
            ->imageResizeTargetWidth('1200')
            ->imageResizeTargetHeight('1200')
            ->imageResizeUpscale(false)
            ->imagePreviewHeight('160')
            ->helperText('JPG, PNG veya WebP; en fazla 5 MB.');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedSchema::make('form'),
            Actions::make([
                Action::make('save')->label('Tasarımı kaydet')->icon(Heroicon::OutlinedCheck)->action(fn () => $this->save()),
            ])->key('builder-actions'),
        ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $template = BomTemplate::query()->findOrFail($data['template_id']);
        $fields = collect($data)->only(['code', 'name', 'description', 'image_path', 'subtype_id', 'phase', 'power_kw', 'speed_rpm', 'pole_count', 'voltage', 'frame_size', 'mounting_type'])->all();

        try {
            $design = app(MotorDesignService::class)->build($template, $fields, $data['rows'] ?? []);
        } catch (BusinessRuleException $exception) {
            BusinessRuleNotification::send($exception);

            return;
        }

        Notification::make()->success()->title("Tasarım kaydedildi: {$design->code}")->body("{$design->items()->count()} bileşen.")->send();
        $this->redirect(MotorDesignResource::getUrl('view', ['record' => $design]));
    }

    private function fillRows(mixed $templateId, Set $set): void
    {
        $rows = [];

        foreach (BomTemplate::query()->find($templateId)?->lines ?? [] as $line) {
            $rows[$line->getKey()] = [
                'part_id' => null, 'description' => null, 'unit_id' => null,
                'quantity' => $line->quantity_per_unit === null ? null : Quantity::toInput((string) $line->quantity_per_unit),
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
        $units = Unit::query()->orderBy('name')->pluck('name', 'id')->all();

        return $template->lines()->where('kind', BomTemplateLine::KIND_COMPONENT)->with('subtype')->get()
            ->map(function (BomTemplateLine $line) use ($service, $units): Grid {
                $key = "rows.{$line->getKey()}";
                $number = $line->drawingLabel();
                $hasPart = fn (Get $get): bool => filled($get("{$key}.part_id"));

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
                            ->placeholder('Ürün seçin (veya aşağıya deneme bileşeni yazın)')
                            ->allowHtml()
                            ->options(fn (): array => PartOption::options($service->candidateParts($line), BomTemplateService::candidateLabel(...)))
                            ->getSearchResultsUsing(fn (string $search): array => PartOption::options($service->candidateParts($line, search: trim($search)), BomTemplateService::candidateLabel(...)))
                            ->getOptionLabelUsing(fn ($value): ?string => ($part = $this->part($value)) ? PartOption::html($part, BomTemplateService::candidateLabel($part)) : null)
                            ->afterStateUpdated(fn ($state, Set $set) => filled($state) ? $set("{$key}.description", null) : null)
                            ->helperText(fn (Get $get): ?string => $this->part($get("{$key}.part_id"))?->description
                                ?? ($line->subtype ? "{$line->component_type->label()} › {$line->subtype->name}" : $line->component_type->label()))
                            ->columnSpan(['default' => 5, 'md' => 7]),
                        TextInput::make("{$key}.quantity")
                            ->label('Miktar')
                            ->inputMode('decimal')
                            ->placeholder('Girin')
                            ->required(fn (Get $get): bool => $hasPart($get) || filled($get("{$key}.description")))
                            ->rule($this->positiveRule('Sıfırdan büyük bir miktar girin (ör. 2,5).'))
                            ->columnSpan(['default' => 3, 'md' => 2]),
                        TextEntry::make("{$key}.unit")
                            ->label('Birim')
                            ->state(fn (Get $get): string => $this->part($get("{$key}.part_id"))?->unit->name ?? '—')
                            ->visible($hasPart)
                            ->columnSpan(['default' => 3, 'md' => 2]),
                        Select::make("{$key}.unit_id")
                            ->label('Birim')
                            ->options($units)
                            ->hidden($hasPart)
                            ->required(fn (Get $get): bool => filled($get("{$key}.description")))
                            ->columnSpan(['default' => 3, 'md' => 2]),
                        TextInput::make("{$key}.description")
                            ->label('Deneme bileşeni (kartı yoksa)')
                            ->placeholder('ör. 6203 rulman (yeni tedarikçi)')
                            ->maxLength(150)
                            ->live(onBlur: true)
                            ->hidden($hasPart)
                            ->columnStart(['default' => 1, 'md' => 2])
                            ->columnSpan(['default' => 6, 'md' => 11]),
                    ]);
            })->all();
    }
}
