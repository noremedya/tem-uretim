<?php

namespace App\Models;

use App\Enums\PartType;
use App\Models\Concerns\HasOptimisticLocking;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Hazır reçete şablonu: bir ürün türü (yarı mamul veya mamul) için satır listesi; uygulanırken her satıra gerçek ürün
 * seçilir. Yalnızca BomTemplateService değiştirir. Silinmez, pasifleştirilir.
 */
class BomTemplate extends Model
{
    use HasOptimisticLocking, LogsActivity;

    protected $guarded = ['*'];

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return ['product_type' => PartType::class, 'is_active' => 'boolean'];
    }

    /** @return HasMany<BomTemplateLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(BomTemplateLine::class)->orderBy('sort_order')->orderBy('id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['name', 'product_type', 'description', 'is_active'])->logOnlyDirty()->dontLogEmptyChanges();
    }
}
