<?php

namespace App\Models;

use App\Enums\MotorPhase;
use App\Models\Concerns\HasOptimisticLocking;
use Database\Factories\MotorDesignFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Tasarım (deneme reçetesi): taslak motor tasarımı. Stoktan düşmez, iş emri açılamaz, motor modeline bağlı değildir.
 * Silinmez, pasifleştirilir.
 */
#[Fillable(['code', 'name', 'description', 'image_path', 'subtype_id', 'phase', 'power_kw', 'speed_rpm', 'pole_count', 'voltage', 'frame_size', 'mounting_type'])]
class MotorDesign extends Model
{
    /** @use HasFactory<MotorDesignFactory> */
    use HasFactory, HasOptimisticLocking, LogsActivity;

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'phase' => MotorPhase::class, 'power_kw' => 'decimal:3', 'speed_rpm' => 'integer', 'pole_count' => 'integer'];
    }

    protected function code(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => mb_strtoupper(trim((string) $value), 'UTF-8'));
    }

    protected function name(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => trim((string) $value));
    }

    /** @return HasMany<MotorDesignItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(MotorDesignItem::class);
    }

    /** @return BelongsTo<BomTemplate, $this> Tasarımın başladığı motor tipi (mamul şablonu). */
    public function template(): BelongsTo
    {
        return $this->belongsTo(BomTemplate::class, 'bom_template_id');
    }

    /** @return BelongsTo<PartSubtype, $this> */
    public function subtype(): BelongsTo
    {
        return $this->belongsTo(PartSubtype::class, 'subtype_id');
    }

    /** @return BelongsTo<MotorModel, $this> Tasarımdan oluşturulan motor modeli. */
    public function motorModel(): BelongsTo
    {
        return $this->belongsTo(MotorModel::class);
    }

    public function imageUrl(): ?string
    {
        return $this->image_path === null ? null : route('motor-designs.image', ['motorDesign' => $this, 'v' => substr(md5($this->image_path), 0, 8)]);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['code', 'name', 'description', 'is_active', 'image_path', 'bom_template_id', 'subtype_id', 'phase',
            'power_kw', 'speed_rpm', 'pole_count', 'voltage', 'frame_size', 'mounting_type', 'motor_model_id'])->logOnlyDirty()->dontLogEmptyChanges();
    }
}
