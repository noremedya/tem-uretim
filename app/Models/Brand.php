<?php

namespace App\Models;

use App\Models\Concerns\HasOptimisticLocking;
use Database\Factories\BrandFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/** Marka (ürünlerde seçilen tanım listesi). Silinmez, pasifleştirilir. */
#[Fillable(['name'])]
class Brand extends Model
{
    /** @use HasFactory<BrandFactory> */
    use HasFactory, HasOptimisticLocking, LogsActivity;

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    protected function name(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => trim((string) $value));
    }

    /** @return HasMany<Part, $this> */
    public function parts(): HasMany
    {
        return $this->hasMany(Part::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['name', 'is_active'])->logOnlyDirty()->dontLogEmptyChanges();
    }
}
