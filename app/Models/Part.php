<?php

namespace App\Models;

use App\Enums\PartType;
use App\Models\Concerns\HasOptimisticLocking;
use App\Support\Quantity;
use Database\Factories\PartFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Stok kartı. Bakiye stock_balances'ta tutulur ve yalnızca StockService tarafından değiştirilir.
 */
#[Fillable(['code', 'name', 'unit_id', 'type', 'barcode', 'critical_level'])]
class Part extends Model
{
    /** @use HasFactory<PartFactory> */
    use HasFactory, HasOptimisticLocking, LogsActivity;

    protected function casts(): array
    {
        return [
            'type' => PartType::class,
            'critical_level' => 'decimal:3',
            'is_active' => 'boolean',
        ];
    }

    protected function code(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => trim((string) $value));
    }

    protected function barcode(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => filled($value) ? trim($value) : null);
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** @return HasOne<StockBalance, $this> */
    public function stockBalance(): HasOne
    {
        return $this->hasOne(StockBalance::class);
    }

    /** @return HasMany<StockMovement, $this> */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /** Kritik seviye takibi var mı? critical_level = 0 takip yok demektir. */
    public function tracksCriticalLevel(): bool
    {
        return Quantity::compare((string) $this->critical_level, '0') > 0;
    }

    /** Verilen bakiyede parça kritik mi? Kural: bakiye <= kritik seviye (takip varsa). */
    public function isCriticalAt(string $quantity): bool
    {
        return $this->tracksCriticalLevel() && Quantity::compare($quantity, (string) $this->critical_level) <= 0;
    }

    /** Kritik seviyedeki parçalar (bakiye <= kritik seviye, kritik seviye > 0). */
    public function scopeCritical(Builder $query): void
    {
        $query->where('critical_level', '>', 0)
            ->whereHas('stockBalance', fn (Builder $balance) => $balance->whereColumn('stock_balances.quantity', '<=', 'parts.critical_level'));
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['code', 'name', 'unit_id', 'type', 'barcode', 'critical_level', 'is_active'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
