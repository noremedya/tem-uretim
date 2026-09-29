<?php

namespace App\Models;

use App\Enums\MotorPhase;
use App\Models\Concerns\HasOptimisticLocking;
use Database\Factories\MotorModelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Fillable(self::FIELDS)]
class MotorModel extends Model
{
    /** @use HasFactory<MotorModelFactory> */
    use HasFactory, HasOptimisticLocking, LogsActivity;

    public const FIELDS = [
        'code', 'name', 'power_kw', 'speed_rpm', 'voltage', 'frequency_hz', 'pole_count', 'frame_size',
        'phase', 'mounting_type', 'protection_class', 'efficiency_class', 'description',
    ];

    protected function casts(): array
    {
        return [
            'power_kw' => 'decimal:3',
            'speed_rpm' => 'integer',
            'frequency_hz' => 'integer',
            'pole_count' => 'integer',
            'phase' => MotorPhase::class,
            'is_active' => 'boolean',
        ];
    }

    protected function code(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => trim((string) $value));
    }

    /** @return HasMany<OrderItem, $this> */
    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([...self::FIELDS, 'is_active'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
