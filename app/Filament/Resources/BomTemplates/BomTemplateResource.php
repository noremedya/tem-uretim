<?php

namespace App\Filament\Resources\BomTemplates;

use App\Enums\PartType;
use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\BomTemplates\Pages\CreateBomTemplate;
use App\Filament\Resources\BomTemplates\Pages\EditBomTemplate;
use App\Filament\Resources\BomTemplates\Pages\ListBomTemplates;
use App\Filament\Support\BusinessRuleNotification;
use App\Models\BomTemplate;
use App\Models\BomTemplateLine;
use App\Models\PartSubtype;
use App\Services\BomTemplateService;
use App\Support\Quantity;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Hazır reçete şablonları (Üretim). Reçeteler ekranında "Şablondan başlat" ile bir ürüne uygulanır; şablonun kendisi
 * reçeteleri etkilemez. Satırlar her kayıtta şablon formundaki listeyle değiştirilir.
 */
class BomTemplateResource extends Resource
{
    protected static ?string $model = BomTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Üretim';

    protected static ?int $navigationSort = 16;

    protected static ?string $modelLabel = 'reçete şablonu';

    protected static ?string $pluralModelLabel = 'reçete şablonları';

    protected static ?string $navigationLabel = 'Reçete şablonları';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->columnSpanFull()->schema([
                TextInput::make('name')->label('Şablon adı')->required()->maxLength(150),
                Select::make('product_type')->label('Hangi ürün için')->required()
                    ->options([PartType::FinishedGood->value => 'Mamul (motor)', PartType::SemiFinished->value => 'Yarı mamul']),
                Textarea::make('description')->label('Açıklama')->rows(2)->columnSpanFull(),
            ]),
            Section::make('Satırlar')->columnSpanFull()->description('Her satırda hangi tür (ve alt tür) ürün seçileceği, varsayılan miktar ve fire. Boş miktar uygulanırken girilir. Fire ya oranla ya brüt/fire miktarıyla.')->schema([
                Repeater::make('lines')
                    ->hiddenLabel()
                    ->reorderable()
                    ->minItems(1)
                    ->defaultItems(1)
                    ->columns(4)
                    ->itemLabel(fn (array $state): ?string => $state['label'] ?? null)
                    ->schema([
                        Select::make('kind')->label('Satır')->required()->default(BomTemplateLine::KIND_COMPONENT)->live()
                            ->options([BomTemplateLine::KIND_COMPONENT => 'Bileşen', BomTemplateLine::KIND_CO_PRODUCT => 'Eş ürün (çıktı)']),
                        TextInput::make('label')->label('Satır adı')->required()->maxLength(150),
                        Select::make('component_type')->label('Tür')->required()->live()
                            ->options(fn (Get $get): array => $get('kind') === BomTemplateLine::KIND_CO_PRODUCT
                                ? [PartType::SemiFinished->value => PartType::SemiFinished->label()]
                                : collect([PartType::RawMaterial, PartType::Component, PartType::DirectConsumable, PartType::SemiFinished])->mapWithKeys(fn (PartType $t) => [$t->value => $t->label()])->all())
                            ->afterStateUpdated(fn (Set $set) => $set('component_subtype_id', null)),
                        Select::make('component_subtype_id')->label('Alt tür')->placeholder('Herhangi')
                            ->options(fn (Get $get): array => blank($get('component_type')) ? [] : PartSubtype::query()->where('type', $get('component_type'))->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all()),
                        TextInput::make('quantity_per_unit')->label('Net miktar')->inputMode('decimal')->placeholder('Uygulanırken girilir'),
                        TextInput::make('scrap_rate')->label('Fire (%)')->inputMode('decimal')->default('0'),
                        TextInput::make('gross_per_unit')->label('Brüt (miktarla fire)')->inputMode('decimal')
                            ->helperText('Doluysa net = brüt − fire; oran kullanılmaz.'),
                        TextInput::make('scrap_quantity_per_unit')->label('Fire miktarı')->inputMode('decimal'),
                    ]),
            ]),
        ]);
    }

    /** Düzenleme formu için satırlar (Türkçe sayı biçiminde). */
    public static function linesForForm(BomTemplate $template): array
    {
        $input = fn ($value): ?string => $value === null ? null : Quantity::toInput((string) $value);

        return $template->lines()->get()->map(fn (BomTemplateLine $line) => [
            'kind' => $line->kind,
            'label' => $line->label,
            'component_type' => $line->component_type->value,
            'component_subtype_id' => $line->component_subtype_id,
            'quantity_per_unit' => $input($line->quantity_per_unit),
            'scrap_rate' => $input($line->scrap_rate),
            'gross_per_unit' => $input($line->gross_per_unit),
            'scrap_quantity_per_unit' => $input($line->scrap_quantity_per_unit),
        ])->all();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label('Şablon')->searchable()->weight('bold')->description(fn (BomTemplate $record): ?string => $record->description),
                TextColumn::make('product_type')->label('Ürün')->badge()->formatStateUsing(fn (PartType $state): string => $state->label()),
                TextColumn::make('lines_count')->label('Satır')->counts('lines')->numeric(),
                IconColumn::make('is_active')->label('Aktif')->boolean(),
            ])
            ->filters([TernaryFilter::make('is_active')->label('Durum')->trueLabel('Aktif')->falseLabel('Pasif')->default(true)])
            ->recordActions([
                EditAction::make(),
                static::status('deactivate', 'Pasifleştir', 'danger', fn (BomTemplate $t) => app(BomTemplateService::class)->deactivate($t), 'Şablon pasifleştirildi'),
                static::status('activate', 'Aktifleştir', 'success', fn (BomTemplate $t) => app(BomTemplateService::class)->activate($t), 'Şablon aktifleştirildi'),
            ]);
    }

    private static function status(string $name, string $label, string $color, \Closure $run, string $done): Action
    {
        return Action::make($name)->label($label)->color($color)->authorize($name)->requiresConfirmation()
            ->action(function (BomTemplate $record, Action $action) use ($run, $done): void {
                try {
                    $run($record);
                } catch (BusinessRuleException $e) {
                    BusinessRuleNotification::send($e);
                    $action->halt();
                }
                Notification::make()->success()->title($done)->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBomTemplates::route('/'),
            'create' => CreateBomTemplate::route('/create'),
            'edit' => EditBomTemplate::route('/{record}/edit'),
        ];
    }
}
