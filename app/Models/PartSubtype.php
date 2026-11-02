<?php

namespace App\Models;

use App\Enums\PartType;
use App\Models\Concerns\HasOptimisticLocking;
use Database\Factories\PartSubtypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/** Alt tür: 6 sabit ana türün altında kullanıcı tanımlı sınıf (tek seviye). Silinmez, pasifleştirilir. */
#[Fillable(['type', 'name', 'default_warehouse_id'])]
class PartSubtype extends Model
{
    /** @use HasFactory<PartSubtypeFactory> */
    use HasFactory, HasOptimisticLocking, LogsActivity;

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return ['type' => PartType::class, 'is_active' => 'boolean'];
    }

    protected function name(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => trim((string) $value));
    }

    /** "Hammadde › Silisli sac" */
    public function fullLabel(): string
    {
        return "{$this->type->label()} › {$this->name}";
    }

    /** Bu alt türün ürünlerinin varsayılan deposu (kartta depo seçilmemişse). @return BelongsTo<Warehouse, $this> */
    public function defaultWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'default_warehouse_id');
    }

    /** @return HasMany<Part, $this> */
    public function parts(): HasMany
    {
        return $this->hasMany(Part::class, 'subtype_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['type', 'name', 'default_warehouse_id', 'is_active'])->logOnlyDirty()->dontLogEmptyChanges();
    }
}
