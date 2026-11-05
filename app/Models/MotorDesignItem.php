<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Tasarımın bileşen satırı: tanımlı bir ürün (birim üründen) veya kartı olmayan deneme bileşeni (ad + birim).
 * Serbestçe eklenir, değiştirilir ve silinir (stok etkisi yoktur).
 */
class MotorDesignItem extends Model
{
    use LogsActivity;

    protected $fillable = ['part_id', 'description', 'quantity', 'unit_id', 'notes'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3'];
    }

    /** @return BelongsTo<MotorDesign, $this> */
    public function design(): BelongsTo
    {
        return $this->belongsTo(MotorDesign::class, 'motor_design_id');
    }

    /** @return BelongsTo<Part, $this> */
    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function label(): string
    {
        return $this->part !== null ? "{$this->part->code} {$this->part->name}" : (string) $this->description;
    }

    public function unitName(): ?string
    {
        return $this->part !== null ? $this->part->unit?->name : $this->unit?->name;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['motor_design_id', 'part_id', 'description', 'quantity', 'unit_id', 'notes'])->logOnlyDirty()->dontLogEmptyChanges();
    }
}
