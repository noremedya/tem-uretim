<?php

namespace App\Models;

use App\Models\Concerns\HasOptimisticLocking;
use App\Support\Quantity;
use Database\Factories\BomItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Reçete satırı: üst kart (yarı mamul veya mamul) bir birim için bileşenden quantity_per_unit (net) kadar kullanır.
 * Fire ya oranla (scrap_rate, net üzerinden %) ya miktarla (scrap_quantity_per_unit; brüt = net + fire) verilir.
 * scrap_part_id doluysa gerçekleşen fire o karta (hurda) stok girişi olur. Yalnızca BomService değiştirir.
 */
#[Fillable(['quantity_per_unit', 'scrap_rate', 'scrap_quantity_per_unit', 'scrap_part_id'])]
class BomItem extends Model
{
    /** @use HasFactory<BomItemFactory> */
    use HasFactory, HasOptimisticLocking, LogsActivity;

    protected function casts(): array
    {
        return [
            'quantity_per_unit' => 'decimal:3',
            'scrap_rate' => 'decimal:2',
            'scrap_quantity_per_unit' => 'decimal:3',
        ];
    }

    /** @return BelongsTo<Part, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Part::class, 'parent_part_id');
    }

    /** @return BelongsTo<Part, $this> */
    public function component(): BelongsTo
    {
        return $this->belongsTo(Part::class, 'component_part_id');
    }

    /** @return BelongsTo<Part, $this> */
    public function scrapPart(): BelongsTo
    {
        return $this->belongsTo(Part::class, 'scrap_part_id');
    }

    public function usesScrapQuantity(): bool
    {
        return $this->scrap_quantity_per_unit !== null;
    }

    /** Birim başına brüt (net + fire); oranlı firede net × (1 + oran/100). */
    public function grossPerUnit(): string
    {
        $net = (string) $this->quantity_per_unit;

        return $this->usesScrapQuantity()
            ? Quantity::add($net, (string) $this->scrap_quantity_per_unit)
            : Quantity::add($net, Quantity::percentOf($net, (string) $this->scrap_rate));
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['parent_part_id', 'component_part_id', 'quantity_per_unit', 'scrap_rate', 'scrap_quantity_per_unit', 'scrap_part_id'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
