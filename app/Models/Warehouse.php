<?php

namespace App\Models;

use App\Enums\PartType;
use App\Models\Concerns\HasOptimisticLocking;
use App\Services\WarehouseService;
use Database\Factories\WarehouseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Depo. Bakiye ve hareketler depo bazlıdır. Silinmez, pasifleştirilir.
 *
 * default_part_types: bu deponun varsayılan depo olduğu ürün türleri (her tür en fazla bir aktif depoda).
 * allows_negative: bu depoda stok eksiye düşebilir mi (mamulde hiçbir zaman; veritabanında da zorlanır).
 */
#[Fillable(['code', 'name', 'allows_negative', 'is_production', 'is_shipping', 'default_part_types'])]
class Warehouse extends Model
{
    /** @use HasFactory<WarehouseFactory> */
    use HasFactory, HasOptimisticLocking, LogsActivity;

    protected $attributes = ['is_active' => true, 'allows_negative' => false, 'is_production' => false, 'is_shipping' => false, 'default_part_types' => '[]'];

    protected static function booted(): void
    {
        // Varsayılan depo eşlemesi istek boyunca önbellekte; depo değişince tazelenir.
        static::saved(fn () => app(WarehouseService::class)->flush());
    }

    protected function casts(): array
    {
        return [
            'allows_negative' => 'boolean',
            'is_production' => 'boolean',
            'is_shipping' => 'boolean',
            'is_active' => 'boolean',
            'default_part_types' => 'array',
        ];
    }

    protected function code(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => mb_strtoupper(trim((string) $value), 'UTF-8'));
    }

    protected function name(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => trim((string) $value));
    }

    /** @return list<PartType> */
    public function defaultTypes(): array
    {
        return array_map(fn (string $type) => PartType::from($type), $this->default_part_types ?? []);
    }

    /** Bu depoda verilen türdeki ürün eksiye düşebilir mi? Mamul hiçbir zaman. */
    public function allowsNegativeFor(Part $part): bool
    {
        return $this->allows_negative && ! $part->isFinishedGood();
    }

    /** @return HasMany<StockBalance, $this> */
    public function stockBalances(): HasMany
    {
        return $this->hasMany(StockBalance::class);
    }

    /** @return HasMany<StockMovement, $this> */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['code', 'name', 'allows_negative', 'is_production', 'is_shipping', 'default_part_types', 'is_active'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
