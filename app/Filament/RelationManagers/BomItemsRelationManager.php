<?php

namespace App\Filament\RelationManagers;

use App\Enums\PartType;
use App\Enums\Permission;
use App\Exceptions\BusinessRuleException;
use App\Filament\Support\BusinessRuleNotification;
use App\Models\BomItem;
use App\Models\MotorModel;
use App\Models\Part;
use App\Services\BomService;
use App\Support\Quantity;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Reçete. Motor modeli sayfasında mamul kartının, yarı mamul kartı sayfasında kartın kendi reçetesini
 * gösterir. Değişiklikler yalnızca BomService üzerinden yapılır.
 */
class BomItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'bomItems';

    protected static ?string $title = 'Reçete';

    protected static ?string $modelLabel = 'reçete satırı';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = auth()->user();

        return match (true) {
            $ownerRecord instanceof MotorModel => $user?->can(Permission::MotorModelsView) ?? false,
            $ownerRecord instanceof Part => $ownerRecord->type === PartType::SemiFinished && ($user?->can(Permission::PartsView) ?? false),
            default => false,
        };
    }

    public function isReadOnly(): bool
    {
        return ! (auth()->user()?->can(Permission::MotorModelsManage) ?? false);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('component_part_id')
                ->label('Bileşen')
                ->required()
                ->searchable()
                ->getSearchResultsUsing(fn (string $search): array => $this->searchComponents($search))
                ->getOptionLabelUsing(fn ($value): ?string => ($part = Part::query()->with('unit')->find($value)) ? $this->componentLabel($part) : null)
                ->hiddenOn('edit'),
            TextInput::make('quantity_per_unit')
                ->label('Birim başına miktar')
                ->required()
                ->inputMode('decimal')
                ->formatStateUsing(fn ($state): ?string => Quantity::toInput($state))
                ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                    if (! Quantity::isValid($value) || Quantity::compare(Quantity::parse($value), '0') <= 0) {
                        $fail('Sıfırdan büyük geçerli bir miktar girin (ör. 2 veya 1,25; en fazla 3 ondalık).');
                    }
                }),
            TextInput::make('scrap_rate')
                ->label('Standart fire (%)')
                ->required()
                ->default('0')
                ->inputMode('decimal')
                ->formatStateUsing(fn ($state): ?string => Quantity::toInput($state))
                ->helperText('Net miktarın üstüne eklenir. Tamamlamada gerçekleşen fire girilebilir.'),
            // Düzenleme penceresinin açıldığı andaki sürüm (optimistic locking).
            Hidden::make('lock_version')->visibleOn('edit'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('component.unit'))
            ->defaultSort('id')
            ->columns([
                TextColumn::make('component.code')->label('Kod')->searchable(),
                TextColumn::make('component.name')->label('Bileşen')->searchable(),
                TextColumn::make('component.type')->label('Tür')->badge(),
                TextColumn::make('quantity_per_unit')
                    ->label('Birim başına')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state, BomItem $record): string => Quantity::format($state, $record->component->unit->name)),
                TextColumn::make('scrap_rate')
                    ->label('Fire')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state): string => '%'.Quantity::format($state)),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Bileşen ekle')
                    ->using(fn (array $data, Action $action): Model => $this->guard($action, fn () => app(BomService::class)->add(
                        $this->parentPart(),
                        (int) $data['component_part_id'],
                        $data['quantity_per_unit'],
                        $data['scrap_rate'] ?? 0,
                    ))),
            ])
            ->recordActions([
                EditAction::make()
                    ->using(fn (BomItem $record, array $data, Action $action): Model => $this->guard($action, fn () => app(BomService::class)->update(
                        $record,
                        $data['quantity_per_unit'],
                        $data['scrap_rate'] ?? 0,
                        (int) $data['lock_version'],
                    ))),
                DeleteAction::make()
                    ->label('Kaldır')
                    ->modalHeading('Bileşeni reçeteden kaldır')
                    ->modalDescription('Açık iş emirleri kendi malzeme listesini kullandığı için etkilenmez.')
                    ->using(fn (BomItem $record, Action $action): bool => $this->guard($action, function () use ($record): bool {
                        app(BomService::class)->remove($record);

                        return true;
                    })),
            ]);
    }

    private function parentPart(): Part
    {
        $owner = $this->getOwnerRecord();

        return $owner instanceof MotorModel ? $owner->finishedPart()->firstOrFail() : $owner;
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

    /** @return array<int, string> Reçeteye girebilen aktif kartlar (endirekt sarf ve mamul hariç). */
    private function searchComponents(string $search): array
    {
        $search = trim($search);

        return Part::query()
            ->with('unit')
            ->where('is_active', true)
            ->whereNotIn('type', [PartType::IndirectConsumable->value, PartType::FinishedGood->value])
            ->whereKeyNot($this->parentPart()->getKey())
            ->where(fn (Builder $query) => $query
                ->where('code', 'ilike', "%{$search}%")
                ->orWhere('name', 'ilike', "%{$search}%")
                ->orWhere('barcode', $search))
            ->orderBy('code')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (Part $part): array => [$part->getKey() => $this->componentLabel($part)])
            ->all();
    }

    private function componentLabel(Part $part): string
    {
        return "{$part->code} — {$part->name} ({$part->unit->name})";
    }
}
