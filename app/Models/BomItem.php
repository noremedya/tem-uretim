<?php

namespace App\Models;

use App\Models\Concerns\HasOptimisticLocking;
use Database\Factories\BomItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Reçete satırı: üst kart (yarı mamul veya mamul) bir birim için bileşenden quantity_per_unit kadar kullanır.
 * Yalnızca BomService değiştirir.
 */
#[Fillable(['quantity_per_unit', 'scrap_rate'])]
class BomItem extends Model
{
    /** @use HasFactory<BomItemFactory> */
    use HasFactory, HasOptimisticLocking, LogsActivity;

    protected function casts(): array
    {
        return [
            'quantity_per_unit' => 'decimal:3',
            'scrap_rate' => 'decimal:2',
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

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['parent_part_id', 'component_part_id', 'quantity_per_unit', 'scrap_rate'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
