<?php

namespace App\Filament\Resources\MotorDesigns\RelationManagers;

use App\Enums\PartType;
use App\Exceptions\BusinessRuleException;
use App\Filament\Support\BusinessRuleNotification;
use App\Filament\Support\PartOption;
use App\Models\MotorDesign;
use App\Models\MotorDesignItem;
use App\Models\Part;
use App\Models\Unit;
use App\Services\MotorDesignService;
use App\Support\Quantity;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Tasarımın bileşenleri: tanımlı ürün veya deneme bileşeni (ad + birim). Serbestçe eklenir, değiştirilir, kaldırılır;
 * stok etkisi yoktur. Pasif tasarımda ve yetkisi olmayanda salt okunur. Değişiklikler MotorDesignService üzerinden.
 */
class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Bileşenler';

    protected static ?string $modelLabel = 'bileşen';

    public function isReadOnly(): bool
    {
        return ! $this->design()->is_active || ! (Auth::user()?->can('update', $this->design()) ?? false);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['part.unit', 'unit']))
            ->defaultSort('id')
            ->paginated(false)
            ->emptyStateHeading('Henüz bileşen yok')
            ->emptyStateDescription('Ürün seçerek veya deneme bileşeni yazarak ekleyin.')
            ->columns([
                ImageColumn::make('image')->label('')->state(fn (MotorDesignItem $record): ?string => $record->part?->imageUrl())->imageSize(40),
                TextColumn::make('label')->label('Bileşen')->state(fn (MotorDesignItem $record): string => $record->label())
                    ->description(fn (MotorDesignItem $record): string => $record->part !== null ? $record->part->type->label() : 'Deneme bileşeni (kartı yok)')
                    ->wrap(),
                TextColumn::make('quantity')->label('Miktar')->alignEnd()
                    ->formatStateUsing(fn ($state, MotorDesignItem $record): string => Quantity::format($state, $record->unitName())),
                TextColumn::make('notes')->label('Not')->wrap()->placeholder('—'),
            ])
            ->headerActions([
                Action::make('addItem')
                    ->label('Bileşen ekle')
                    ->icon('heroicon-o-plus')
                    ->visible(fn (): bool => ! $this->isReadOnly())
                    ->schema(fn (): array => $this->fields(null))
                    ->action(fn (array $data, Action $action) => $this->guard($action, fn () => app(MotorDesignService::class)->addItem($this->design(), $this->payload($data)))),
            ])
            ->recordActions([
                EditAction::make()
                    ->schema(fn (MotorDesignItem $record): array => $this->fields($record))
                    ->fillForm(fn (MotorDesignItem $record): array => [
                        'kind' => $record->part_id !== null ? 'part' : 'trial',
                        'part_id' => $record->part_id,
                        'description' => $record->description,
                        'unit_id' => $record->unit_id,
                        'quantity' => Quantity::toInput((string) $record->quantity),
                        'notes' => $record->notes,
                    ])
                    ->using(fn (MotorDesignItem $record, array $data, Action $action): Model => $this->guard($action, fn () => app(MotorDesignService::class)->updateItem($record, $this->payload($data)))),
                DeleteAction::make()
                    ->label('Kaldır')
                    ->modalHeading('Bileşeni kaldır')
                    ->using(fn (MotorDesignItem $record, Action $action): bool => $this->guard($action, function () use ($record): bool {
                        app(MotorDesignService::class)->removeItem($record);

                        return true;
                    })),
            ]);
    }

    /** @return array<int, mixed> */
    private function fields(?MotorDesignItem $item): array
    {
        return [
            Radio::make('kind')
                ->label('Bileşen')
                ->options(['part' => 'Tanımlı ürün', 'trial' => 'Deneme bileşeni (ürün kartı yok)'])
                ->default('part')
                ->inline()
                ->live(),
            Select::make('part_id')
                ->label('Ürün')
                ->visible(fn (Get $get): bool => $get('kind') !== 'trial')
                ->required(fn (Get $get): bool => $get('kind') !== 'trial')
                ->searchable()
                ->allowHtml()
                ->getSearchResultsUsing(fn (string $search): array => PartOption::options(
                    $this->selectableParts()->where(fn (Builder $q) => $q->whereLike('code', "%{$search}%")->orWhereLike('name', "%{$search}%"))->limit(50)->get(),
                    fn (Part $part): string => "{$part->code} — {$part->name} ({$part->unit->name})",
                ))
                ->getOptionLabelUsing(fn ($value): ?string => ($part = Part::query()->with('unit')->find($value)) === null ? null
                    : PartOption::html($part, "{$part->code} — {$part->name} ({$part->unit->name})")),
            TextInput::make('description')
                ->label('Deneme bileşeninin adı')
                ->visible(fn (Get $get): bool => $get('kind') === 'trial')
                ->required(fn (Get $get): bool => $get('kind') === 'trial')
                ->maxLength(150),
            Select::make('unit_id')
                ->label('Birim')
                ->visible(fn (Get $get): bool => $get('kind') === 'trial')
                ->required(fn (Get $get): bool => $get('kind') === 'trial')
                ->options(fn (): array => Unit::query()->orderBy('name')->pluck('name', 'id')->all()),
            TextInput::make('quantity')
                ->label('Miktar')
                ->required()
                ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                    if (! Quantity::isValid($value)) {
                        $fail('Geçersiz miktar. Örnek: 12 veya 12,5.');
                    }
                }),
            TextInput::make('notes')->label('Not')->maxLength(500),
        ];
    }

    /** Reçetedeki gibi: aktif, mamul ve endirekt sarf dışındaki ürünler. */
    private function selectableParts(): Builder
    {
        return Part::query()->with('unit')->where('is_active', true)
            ->whereNotIn('type', [PartType::FinishedGood->value, PartType::IndirectConsumable->value])
            ->orderBy('code');
    }

    private function payload(array $data): array
    {
        $trial = ($data['kind'] ?? 'part') === 'trial';

        return [
            'part_id' => $trial ? null : ($data['part_id'] ?? null),
            'description' => $trial ? ($data['description'] ?? null) : null,
            'unit_id' => $trial ? ($data['unit_id'] ?? null) : null,
            'quantity' => (string) ($data['quantity'] ?? ''),
            'notes' => $data['notes'] ?? null,
        ];
    }

    private function design(): MotorDesign
    {
        /** @var MotorDesign */
        return $this->getOwnerRecord();
    }

    private function guard(Action $action, Closure $callback): mixed
    {
        try {
            return $callback();
        } catch (BusinessRuleException $exception) {
            BusinessRuleNotification::send($exception);
            $action->halt();
        }

        return null;
    }
}
