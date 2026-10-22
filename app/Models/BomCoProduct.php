<?php

namespace App\Models;

use App\Models\Concerns\HasOptimisticLocking;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Eş ürün: ana yarı mamul bir birim üretilirken aynı işlemde quantity_per_unit kadar çıkan başka bir yarı mamul (ör. aynı
 * kalıpta basılan stator ve rotor paketi). Eş ürünün kendi reçetesi yoktur. Yalnızca BomService değiştirir.
 */
class BomCoProduct extends Model
{
    use HasOptimisticLocking, LogsActivity;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['quantity_per_unit' => 'decimal:3'];
    }

    /** @return BelongsTo<Part, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Part::class, 'parent_part_id');
    }

    /** @return BelongsTo<Part, $this> */
    public function coPart(): BelongsTo
    {
        return $this->belongsTo(Part::class, 'co_part_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['parent_part_id', 'co_part_id', 'quantity_per_unit'])->logOnlyDirty()->dontLogEmptyChanges();
    }
}
