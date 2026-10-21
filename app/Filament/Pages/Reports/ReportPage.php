<?php

namespace App\Filament\Pages\Reports;

use App\Enums\OrderStatus;
use App\Enums\PartType;
use App\Enums\Role;
use App\Models\Customer;
use App\Models\MotorModel;
use App\Models\Part;
use App\Models\PartSubtype;
use App\Models\ProductionBatch;
use App\Models\User;
use App\Models\Warehouse;
use App\Reports\Report;
use App\Reports\ReportColumn;
use App\Reports\ReportFilters;
use App\Reports\ReportFormatter;
use App\Reports\ReportPeriod;
use App\Services\ReportExportService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Rapor sayfası: filtreler, özet, tablo (ilk 500 satır) ve Excel'e aktarım. Rapor tanımı app/Reports'tadır.
 */
abstract class ReportPage extends Page implements HasTable
{
    use InteractsWithTable;

    /** Ekranda gösterilen en fazla satır (tamamı Excel'dedir). */
    public const SCREEN_LIMIT = 500;

    protected static string|UnitEnum|null $navigationGroup = 'Raporlar';

    /** @var array<string, mixed>|null */
    public ?array $filters = [];

    /** @var array{hash: string, rows: list<array<string, mixed>>}|null */
    private ?array $cache = null;

    /** @return class-string<Report> */
    abstract protected static function reportClass(): string;

    public static function report(): Report
    {
        return app(static::reportClass());
    }

    public static function canAccess(): bool
    {
        return static::report()->canView(Auth::user());
    }

    public static function getNavigationLabel(): string
    {
        return static::report()->title();
    }

    public function getTitle(): string
    {
        return static::report()->title();
    }

    public function mount(): void
    {
        [$from, $to] = ReportPeriod::range('this_month');
        $this->form->fill(['preset' => 'this_month', 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'grouping' => 'day']);
    }

    public function currentFilters(): ReportFilters
    {
        return ReportFilters::fromArray($this->filters ?? []);
    }

    /** @return list<array<string, mixed>> */
    public function rows(): array
    {
        $filters = $this->currentFilters();
        $hash = md5(serialize($filters->toArray()));

        if ($this->cache === null || $this->cache['hash'] !== $hash) {
            $this->cache = ['hash' => $hash, 'rows' => static::report()->rows($filters)];
        }

        return $this->cache['rows'];
    }

    public function form(Schema $schema): Schema
    {
        $used = static::report()->filters();
        $fields = [
            Select::make('preset')->label('Dönem')->options(ReportPeriod::PRESETS)->required()->live()
                ->afterStateUpdated(function (?string $state, Set $set): void {
                    if ($state !== null && $state !== 'custom') {
                        [$from, $to] = ReportPeriod::range($state);
                        $set('from', $from->toDateString());
                        $set('to', $to->toDateString());
                    }
                }),
            DatePicker::make('from')->label('Başlangıç')->required()->live()->native(false)->displayFormat('d.m.Y')
                ->afterStateUpdated(fn (Set $set) => $set('preset', 'custom')),
            DatePicker::make('to')->label('Bitiş')->required()->live()->native(false)->displayFormat('d.m.Y')->afterOrEqual('from')
                ->afterStateUpdated(fn (Set $set) => $set('preset', 'custom')),
        ];

        $optional = [
            'grouping' => fn () => Select::make('grouping')->label('Kırılım')->options(['day' => 'Gün', 'week' => 'Hafta', 'month' => 'Ay'])->required(),
            'motor_model_id' => fn () => Select::make('motor_model_id')->label('Model')->searchable()->placeholder('Tümü')
                ->options(fn () => MotorModel::query()->orderBy('code')->get()->mapWithKeys(fn ($m) => [$m->id => "{$m->code} — {$m->name}"])->all()),
            'operator_id' => fn () => Select::make('operator_id')->label('Operatör')->searchable()->placeholder('Tümü')
                ->options(fn () => User::query()->role(Role::Operator->value)->orderBy('name')->pluck('name', 'id')->all()),
            'batch_id' => fn () => Select::make('batch_id')->label('Parti')->searchable()->placeholder('Tümü')
                ->options(fn () => ProductionBatch::query()->orderByDesc('date')->pluck('batch_number', 'id')->all()),
            'part_type' => fn () => Select::make('part_type')->label('Kart türü')->placeholder('Tümü')->options(PartType::class)->live(),
            'subtype_id' => fn () => Select::make('subtype_id')->label('Alt tür')->placeholder('Tümü')->searchable()
                ->options(fn (Get $get) => PartSubtype::query()
                    ->when(filled($get('part_type')), fn (Builder $q) => $q->where('type', $get('part_type') instanceof PartType ? $get('part_type')->value : $get('part_type')))
                    ->get()->sortBy(fn (PartSubtype $s) => $s->fullLabel())->mapWithKeys(fn (PartSubtype $s) => [$s->getKey() => $s->fullLabel()])->all()),
            'part_id' => fn () => Select::make('part_id')->label('Ürün')->searchable()->placeholder('Tümü')
                ->getSearchResultsUsing(fn (string $search) => Part::query()
                    ->where(fn (Builder $q) => $q->where('code', 'ilike', "%{$search}%")->orWhere('name', 'ilike', "%{$search}%"))
                    ->orderBy('code')->limit(50)->get()->mapWithKeys(fn ($p) => [$p->id => "{$p->code} — {$p->name}"])->all())
                ->getOptionLabelUsing(fn ($value) => ($p = Part::query()->find($value)) ? "{$p->code} — {$p->name}" : null),
            'warehouse_id' => fn () => Select::make('warehouse_id')->label('Depo')->placeholder('Tüm depolar')
                ->options(fn () => Warehouse::query()->orderBy('code')->pluck('name', 'id')->all()),
            'customer_id' => fn () => Select::make('customer_id')->label('Müşteri')->searchable()->placeholder('Tümü')
                ->options(fn () => Customer::query()->where('is_customer', true)->orderBy('name')->pluck('name', 'id')->all()),
            'order_status' => fn () => Select::make('order_status')->label('Sipariş durumu')->placeholder('Tümü')->options(OrderStatus::class),
            'include_semi_finished' => fn () => Toggle::make('include_semi_finished')->label('Yarı mamul dahil')->inline(false),
        ];

        foreach ($used as $name) {
            $fields[] = $optional[$name]()->live();
        }

        return $schema->statePath('filters')->columns(['default' => 1, 'sm' => 3, 'lg' => 6])->components($fields);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => collect($this->rows())->take(self::SCREEN_LIMIT)->values()
                ->mapWithKeys(fn (array $row, int $i) => ["r{$i}" => $row])->all())
            ->paginated(false)
            ->striped()
            ->emptyStateHeading('Bu filtrelerle kayıt yok')
            ->columns(array_map(fn (ReportColumn $column) => TextColumn::make($column->key)
                ->label($column->label)
                ->alignEnd($column->isNumeric())
                ->formatStateUsing(fn ($state) => ReportFormatter::display($column, $state))
                ->placeholder('—'),
                static::report()->columns()));
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->schema([EmbeddedSchema::make('form')]),
            Section::make('Özet')
                ->columns(['default' => 1, 'sm' => 3])
                ->schema(fn (): array => collect(static::report()->summary($this->currentFilters(), $this->rows()))
                    ->map(fn (string $value, string $label) => Text::make("{$label}: {$value}"))
                    ->values()->all()),
            Text::make(fn (): string => sprintf('Ekranda ilk %d satır gösteriliyor; tamamı (%d satır) Excel dosyasındadır.', self::SCREEN_LIMIT, count($this->rows())))
                ->visible(fn (): bool => count($this->rows()) > self::SCREEN_LIMIT)
                ->color('warning'),
            EmbeddedTable::make(),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->label("Excel'e aktar")
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->action(function () {
                    $response = app(ReportExportService::class)->export(static::report(), $this->currentFilters(), Auth::user());

                    if ($response === null) {
                        Notification::make()->info()->title('Rapor hazırlanıyor')
                            ->body('Satır sayısı fazla olduğu için dosya arka planda hazırlanıyor; bitince bildirim gelecek.')->send();

                        return null;
                    }

                    return $response;
                }),
        ];
    }
}
