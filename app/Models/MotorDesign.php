<?php

namespace App\Models;

use App\Models\Concerns\HasOptimisticLocking;
use Database\Factories\MotorDesignFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Tasarım (deneme reçetesi): taslak motor tasarımı. Stoktan düşmez, iş emri açılamaz, motor modeline bağlı değildir.
 * Silinmez, pasifleştirilir.
 */
#[Fillable(['code', 'name', 'description'])]
class MotorDesign extends Model
{
    /** @use HasFactory<MotorDesignFactory> */
    use HasFactory, HasOptimisticLocking, LogsActivity;

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
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

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['code', 'name', 'description', 'is_active'])->logOnlyDirty()->dontLogEmptyChanges();
    }
}
