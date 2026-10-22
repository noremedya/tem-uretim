<?php

namespace App\Filament\Support;

use App\Enums\PartType;
use App\Exceptions\BusinessRuleException;
use App\Models\BomItem;
use App\Models\Part;
use App\Services\BomService;
use App\Support\Quantity;
use Closure;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder;

/**
 * Reçete satırı formu ve tablo sütunları (motor modeli/ürün sayfasındaki Reçete sekmesi ve Reçeteler ekranı ortak).
 *
 * Fire iki yöntemle girilir: oran (net miktar + %fire) veya miktar (birim başına brüt ve fire; net = brüt − fire).
 * İsteğe bağlı fire (hurda) kartı: gerçekleşen fire tamamlamada o karta stok girişi olur.
 */
final class BomLineForm
{
    /**
     * @param  Closure(): Part  $parent
     * @return array<int, mixed>
     */
    public static function fields(Closure $parent, bool $creating): array
    {
        return array_values(array_filter([
            $creating ? Select::make('component_part_id')
                ->label('Bileşen')
                ->required()
                ->searchable()
                ->live()
                ->getSearchResultsUsing(fn (string $search): array => self::searchComponents($search, $parent()))
                ->getOptionLabelUsing(fn ($value): ?string => ($part = Part::query()->with('unit')->find($value)) ? self::label($part) : null) : null,
            Radio::make('scrap_mode')
                ->label('Fire')
                ->options(['rate' => 'Oran (%)', 'quantity' => 'Miktar (brüt / fire)'])
                ->default('rate')
                ->inline()
                ->live()
                ->helperText('Ağırlıkla izlenen malzemede (sac, mil çeliği) brüt ve fire miktarı; adetli malzemede oran.'),
            TextInput::make('quantity_per_unit')
                ->label(fn (Get $get): string => 'Net miktar (birim başına'.self::unitSuffix($get).')')
                ->required(fn (Get $get): bool => $get('scrap_mode') !== 'quantity')
                ->visible(fn (Get $get): bool => $get('scrap_mode') !== 'quantity')
                ->inputMode('decimal')
                ->rule(self::quantityRule(positive: true)),
            TextInput::make('scrap_rate')
                ->label('Standart fire (%)')
                ->default('0')
                ->visible(fn (Get $get): bool => $get('scrap_mode') !== 'quantity')
                ->inputMode('decimal')
                ->helperText('Net miktarın üstüne eklenir. Tamamlamada gerçekleşen fire girilebilir.'),
            TextInput::make('gross_per_unit')
                ->label(fn (Get $get): string => 'Brüt (birim başına'.self::unitSuffix($get).')')
                ->required(fn (Get $get): bool => $get('scrap_mode') === 'quantity')
                ->visible(fn (Get $get): bool => $get('scrap_mode') === 'quantity')
                ->inputMode('decimal')
                ->rule(self::quantityRule(positive: true))
                ->helperText('İşleme giren miktar, ör. 6,61 kg.'),
            TextInput::make('scrap_quantity_per_unit')
                ->label(fn (Get $get): string => 'Fire (birim başına'.self::unitSuffix($get).')')
                ->required(fn (Get $get): bool => $get('scrap_mode') === 'quantity')
                ->visible(fn (Get $get): bool => $get('scrap_mode') === 'quantity')
                ->inputMode('decimal')
                ->rule(self::quantityRule(positive: false))
                ->helperText('Ör. 2,88 kg. Net = brüt − fire.'),
            Select::make('scrap_part_id')
                ->label('Fire kartı (hurda)')
                ->placeholder('Yok — fire yalnızca düşülür')
                ->searchable()
                ->getSearchResultsUsing(fn (string $search, Get $get, ?BomItem $record): array => self::searchScrapParts($search, $get('component_part_id') ?? $record?->component_part_id))
                ->getOptionLabelUsing(fn ($value): ?string => ($part = Part::query()->with('unit')->find($value)) ? self::label($part) : null)
                ->helperText('İsteğe bağlı: gerçekleşen fire bu karta stok girişi olur (aynı birimde, ör. HURDA-SAC kg).'),
            Hidden::make('lock_version'),
        ]));
    }

    /** Düzenleme formunun ilk değerleri. */
    public static function fill(BomItem $item): array
    {
        $quantityMode = $item->usesScrapQuantity();

        return [
            'scrap_mode' => $quantityMode ? 'quantity' : 'rate',
            'quantity_per_unit' => Quantity::toInput((string) $item->quantity_per_unit),
            'scrap_rate' => Quantity::toInput((string) $item->scrap_rate),
            'gross_per_unit' => $quantityMode ? Quantity::toInput($item->grossPerUnit()) : null,
            'scrap_quantity_per_unit' => $quantityMode ? Quantity::toInput((string) $item->scrap_quantity_per_unit) : null,
            'scrap_part_id' => $item->scrap_part_id,
            'lock_version' => $item->lock_version,
        ];
    }

    /** Form verisini servise uygular (ekleme veya güncelleme). */
    public static function save(Part $parent, array $data, ?BomItem $item = null): BomItem
    {
        [$net, $rate, $scrapQuantity] = self::values($data);
        $scrapPart = filled($data['scrap_part_id'] ?? null) ? (int) $data['scrap_part_id'] : null;
        $service = app(BomService::class);

        $saved = $item === null
            ? $service->add($parent, (int) $data['component_part_id'], $net, $rate, $scrapQuantity, $scrapPart)
            : $service->update($item, $net, $rate, (int) $data['lock_version'], $scrapQuantity, $scrapPart);

        Notification::make()->success()->title($item === null ? 'Bileşen eklendi' : 'Reçete satırı kaydedildi')->send();

        return $saved;
    }

    /** @return array<int, TextColumn> */
    public static function columns(): array
    {
        $unit = fn (BomItem $record): string => $record->component->unit->name;

        return [
            TextColumn::make('component.code')->label('Kod')->searchable(),
            TextColumn::make('component.name')->label('Bileşen')->searchable()
                ->description(fn (BomItem $record): ?string => $record->scrapPart ? "Fire → {$record->scrapPart->code}" : null),
            TextColumn::make('component.type')->label('Tür')->badge()->toggleable(),
            TextColumn::make('quantity_per_unit')->label('Net (birim başına)')->alignEnd()
                ->formatStateUsing(fn ($state, BomItem $record): string => Quantity::format($state, $unit($record))),
            TextColumn::make('scrap')->label('Fire')->alignEnd()
                ->state(fn (BomItem $record): string => $record->usesScrapQuantity()
                    ? Quantity::format((string) $record->scrap_quantity_per_unit, $unit($record))
                    : '%'.Quantity::format((string) $record->scrap_rate)),
            TextColumn::make('gross')->label('Brüt')->alignEnd()
                ->state(fn (BomItem $record): string => Quantity::format($record->grossPerUnit(), $unit($record))),
        ];
    }

    /** @return array{0: string, 1: string, 2: ?string} net, oran, birim başına fire miktarı */
    private static function values(array $data): array
    {
        if (($data['scrap_mode'] ?? 'rate') === 'quantity') {
            $gross = Quantity::parse($data['gross_per_unit'] ?? '');
            $scrap = Quantity::parse($data['scrap_quantity_per_unit'] ?? '0');

            if (Quantity::compare($scrap, $gross) >= 0) {
                throw new BusinessRuleException('Fire brüt miktardan küçük olmalıdır.');
            }

            return [Quantity::sub($gross, $scrap), '0', $scrap];
        }

        return [$data['quantity_per_unit'], $data['scrap_rate'] ?? '0', null];
    }

    private static function quantityRule(bool $positive): Closure
    {
        return fn (): Closure => function (string $attribute, mixed $value, Closure $fail) use ($positive): void {
            if (blank($value)) {
                return;
            }

            if (! Quantity::isValid($value) || Quantity::compare(Quantity::parse($value), '0') < ($positive ? 1 : 0)) {
                $fail('Geçerli bir miktar girin (ör. 2 veya 1,25; en fazla 3 ondalık).');
            }
        };
    }

    private static function unitSuffix(Get $get): string
    {
        $unit = filled($get('component_part_id')) ? Part::query()->with('unit')->find($get('component_part_id'))?->unit->name : null;

        return $unit ? ", {$unit}" : '';
    }

    /** @return array<int, string> Reçeteye girebilen aktif kartlar (endirekt sarf ve mamul hariç). */
    private static function searchComponents(string $search, Part $parent): array
    {
        $search = trim($search);

        return Part::query()
            ->with('unit')
            ->where('is_active', true)
            ->whereNotIn('type', [PartType::IndirectConsumable->value, PartType::FinishedGood->value])
            ->whereKeyNot($parent->getKey())
            ->where(fn (Builder $query) => $query
                ->where('code', 'ilike', "%{$search}%")
                ->orWhere('name', 'ilike', "%{$search}%")
                ->orWhere('barcode', $search))
            ->orderBy('code')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (Part $part): array => [$part->getKey() => self::label($part)])
            ->all();
    }

    /** @return array<int, string> Fire kartı adayları: aktif, mamul değil, bileşenle aynı birimde. */
    private static function searchScrapParts(string $search, mixed $componentId): array
    {
        $component = filled($componentId) ? Part::query()->find($componentId) : null;

        return Part::query()
            ->with('unit')
            ->where('is_active', true)
            ->where('type', '!=', PartType::FinishedGood->value)
            ->when($component, fn (Builder $q) => $q->where('unit_id', $component->unit_id)->whereKeyNot($component->getKey()))
            ->where(fn (Builder $query) => $query->where('code', 'ilike', "%{$search}%")->orWhere('name', 'ilike', "%{$search}%"))
            ->orderBy('code')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (Part $part): array => [$part->getKey() => self::label($part)])
            ->all();
    }

    public static function label(Part $part): string
    {
        return "{$part->code} — {$part->name} ({$part->unit->name})";
    }
}
