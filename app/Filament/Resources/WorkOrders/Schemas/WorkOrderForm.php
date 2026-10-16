<?php

namespace App\Filament\Resources\WorkOrders\Schemas;

use App\Enums\OrderStatus;
use App\Enums\PartType;
use App\Enums\Role;
use App\Enums\WorkOrderStatus;
use App\Models\OrderItem;
use App\Models\Part;
use App\Models\User;
use App\Models\WorkOrder;
use App\Support\Quantity;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class WorkOrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Üretim')
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    Select::make('product_part_id')
                        ->label('Üretilecek')
                        ->required()
                        ->searchable()
                        ->live()
                        ->getSearchResultsUsing(fn (string $search): array => static::searchProducts($search))
                        ->getOptionLabelUsing(fn ($value): ?string => ($part = Part::query()->find($value)) ? static::productLabel($part) : null)
                        ->disabledOn('edit')
                        ->helperText('Reçetesi tanımlı aktif yarı mamul veya mamul (motor).'),
                    TextInput::make('quantity')
                        ->label('Adet')
                        ->required()
                        ->inputMode('decimal')
                        ->formatStateUsing(fn ($state): ?string => Quantity::toInput($state))
                        ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                            if (! Quantity::isValid($value) || Quantity::compare(Quantity::parse($value), '0') <= 0) {
                                $fail('Sıfırdan büyük geçerli bir miktar girin.');
                            }
                        }),
                    Select::make('order_item_id')
                        ->label('Sipariş kalemi')
                        ->placeholder('Siparişe bağlı değil')
                        ->options(fn (Get $get, ?WorkOrder $record): array => static::orderItemOptions($get('product_part_id'), $record))
                        ->visible(fn (Get $get): bool => Part::query()->whereKey($get('product_part_id'))->where('type', PartType::FinishedGood->value)->exists())
                        ->disabled(fn (?WorkOrder $record): bool => $record !== null && $record->status !== WorkOrderStatus::Planned)
                        ->helperText('Yalnızca açık veya kısmi siparişlerin aynı modeldeki kalemleri. Kalem kotası aşılamaz.'),
                    Select::make('production_batch_id')
                        ->label('Parti')
                        ->relationship('productionBatch', 'batch_number')
                        ->searchable()
                        ->preload(),
                ]),
            Section::make('Planlama')
                ->columns(3)
                ->columnSpanFull()
                ->schema([
                    Select::make('assigned_user_id')
                        ->label('Atanan operatör')
                        ->placeholder('Atanmadı')
                        ->options(fn (): array => User::query()
                            ->where('is_active', true)
                            ->role(Role::Operator->value)
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->searchable()
                        ->helperText('Atanmamış iş emrini yalnızca yönetici başlatır.'),
                    DatePicker::make('planned_start')->label('Planlanan başlangıç')->native(false)->displayFormat('d.m.Y'),
                    DatePicker::make('planned_end')
                        ->label('Planlanan bitiş')
                        ->required()
                        ->native(false)
                        ->displayFormat('d.m.Y')
                        ->afterOrEqual('planned_start'),
                    Textarea::make('notes')->label('Notlar')->rows(3)->columnSpanFull(),
                ]),
        ]);
    }

    /** @return array<int, string> */
    private static function searchProducts(string $search): array
    {
        $search = trim($search);

        return Part::query()
            ->where('is_active', true)
            ->whereIn('type', [PartType::SemiFinished->value, PartType::FinishedGood->value])
            ->whereHas('bomItems')
            ->where(fn (Builder $query) => $query->where('code', 'ilike', "%{$search}%")->orWhere('name', 'ilike', "%{$search}%"))
            ->orderBy('code')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (Part $part): array => [$part->getKey() => static::productLabel($part)])
            ->all();
    }

    private static function productLabel(Part $part): string
    {
        return "{$part->code} — {$part->name} ({$part->type->label()})";
    }

    /** @return array<int, string> */
    private static function orderItemOptions(mixed $productId, ?WorkOrder $record): array
    {
        if (blank($productId)) {
            return [];
        }

        return OrderItem::query()
            ->with('order.customer')
            ->whereHas('motorModel', fn (Builder $query) => $query->where('finished_part_id', $productId))
            ->where(fn (Builder $query) => $query
                ->whereHas('order', fn (Builder $order) => $order->whereIn('status', [OrderStatus::Open->value, OrderStatus::Partial->value]))
                ->when($record?->order_item_id, fn (Builder $q, int $id) => $q->orWhere('id', $id)))
            ->withSum(['workOrders as planned_quantity' => fn (Builder $query) => $query
                ->where('status', '!=', WorkOrderStatus::Cancelled->value)
                ->when($record, fn (Builder $q) => $q->whereKeyNot($record->getKey()))], 'quantity')
            ->get()
            ->mapWithKeys(fn (OrderItem $item): array => [$item->getKey() => sprintf(
                '%s — %s — %s adet (kalan %s)',
                $item->order->order_number,
                $item->order->customer->name,
                $item->quantity,
                Quantity::format(Quantity::sub((string) $item->quantity, (string) ($item->planned_quantity ?? 0))),
            )])
            ->all();
    }
}
