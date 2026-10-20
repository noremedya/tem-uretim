<?php

namespace App\Models;

use App\Support\Quantity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ürünün bir depodaki güncel bakiyesi ve o depodaki kritik seviyesi (hızlı okuma için). Tek yazıcısı StockService'tir.
 */
class StockBalance extends Model
{
    public const CREATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'critical_level' => 'decimal:3',
            'is_below_critical' => 'boolean',
        ];
    }

    /** @return BelongsTo<Part, $this> */
    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** Kritik seviyedeki satırlar (kritik seviye > 0 ve bakiye <= kritik seviye). */
    public function scopeCriticalOnly(Builder $query): void
    {
        $query->where('stock_balances.critical_level', '>', 0)->whereColumn('stock_balances.quantity', '<=', 'stock_balances.critical_level');
    }

    /** Kritik seviye takibi var mı? 0 = takip yok. */
    public function tracksCriticalLevel(): bool
    {
        return Quantity::compare((string) $this->critical_level, '0') > 0;
    }

    /** Bakiye kritik seviyeye eşit veya altında mı (takip varsa)? */
    public function isCriticalAt(?string $quantity = null): bool
    {
        return $this->tracksCriticalLevel() && Quantity::compare($quantity ?? (string) $this->quantity, (string) $this->critical_level) <= 0;
    }
}
