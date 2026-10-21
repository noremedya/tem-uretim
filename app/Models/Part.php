<?php

namespace App\Models;

use App\Enums\PartType;
use App\Models\Concerns\HasOptimisticLocking;
use App\Services\WarehouseService;
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
 * Stok kartı. Bakiye ve kritik seviye depo bazlıdır (stock_balances) ve yalnızca StockService tarafından değiştirilir.
 * Bakiyeyle ilgili yardımcılar yüklü stockBalances ilişkisini kullanır (with('stockBalances')).
 */
#[Fillable(['code', 'name', 'unit_id', 'type', 'barcode', 'image_path', 'brand_id', 'description', 'default_warehouse_id', 'subtype_id'])]
class Part extends Model
{
    /** @use HasFactory<PartFactory> */
    use HasFactory, HasOptimisticLocking, LogsActivity;

    protected function casts(): array
    {
        return [
            'type' => PartType::class,
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

    /** Görseli olan kartın görsel adresi (oturum ister; değişince adres de değişir, tarayıcı önbelleği tazelenir). */
    public function imageUrl(): ?string
    {
        return $this->image_path === null ? null : route('parts.image', ['part' => $this, 'v' => substr(md5($this->image_path), 0, 8)]);
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** @return BelongsTo<PartSubtype, $this> */
    public function subtype(): BelongsTo
    {
        return $this->belongsTo(PartSubtype::class, 'subtype_id');
    }

    /** "Hammadde › Silisli sac" veya yalnızca ana tür. */
    public function typeLabel(): string
    {
        return $this->type->label().($this->subtype_id !== null ? ' › '.$this->subtype->name : '');
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** @return HasMany<StockBalance, $this> */
    public function stockBalances(): HasMany
    {
        return $this->hasMany(StockBalance::class);
    }

    /**
     * Ürüne özel varsayılan depo (boşsa türün varsayılan deposu kullanılır).
     *
     * @return BelongsTo<Warehouse, $this>
     */
    public function defaultWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'default_warehouse_id');
    }

    /** Etkin varsayılan depo id'si (ürünün kendi deposu, yoksa türünün deposu). */
    public function defaultWarehouseId(): ?int
    {
        return app(WarehouseService::class)->defaultIdFor($this);
    }

    /** Tüm depolardaki toplam bakiye. */
    public function totalStock(): string
    {
        return $this->stockBalances->reduce(fn (string $sum, StockBalance $b) => Quantity::add($sum, (string) $b->quantity), '0');
    }

    /** Bir depodaki bakiye satırı (yoksa null; hiç hareket görmemiş depo). */
    public function balanceIn(?int $warehouseId): ?StockBalance
    {
        return $this->stockBalances->firstWhere('warehouse_id', $warehouseId);
    }

    /** Bir depodaki bakiye (satır yoksa 0). */
    public function quantityIn(?int $warehouseId): string
    {
        return (string) ($this->balanceIn($warehouseId)?->quantity ?? '0.000');
    }

    /**
     * Varsayılan depodaki bakiye satırı ilişkisi (tek kayıt üzerinde sorgu için; listelerde with('stockBalances')).
     *
     * @return HasOne<StockBalance, $this>
     */
    public function defaultStockBalance(): HasOne
    {
        return $this->hasOne(StockBalance::class)->where('warehouse_id', $this->defaultWarehouseId());
    }

    /** Varsayılan depodaki bakiye (üretim sarfı ve hızlı işlem bu depoda yapılır). */
    public function defaultQuantity(): string
    {
        return $this->quantityIn($this->defaultWarehouseId());
    }

    /** Varsayılan depodaki bakiye satırı. */
    public function defaultBalance(): ?StockBalance
    {
        return $this->balanceIn($this->defaultWarehouseId());
    }

    /** Varsayılan depodaki kritik seviye (ürün formunda düzenlenen). */
    public function criticalLevel(): string
    {
        return (string) ($this->defaultBalance()?->critical_level ?? '0');
    }

    /** @return HasMany<StockMovement, $this> */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /**
     * Bu kartın reçetesi (kart yarı mamul veya mamulse).
     *
     * @return HasMany<BomItem, $this>
     */
    public function bomItems(): HasMany
    {
        return $this->hasMany(BomItem::class, 'parent_part_id');
    }

    /**
     * Bu kartın bileşen olarak geçtiği reçete satırları.
     *
     * @return HasMany<BomItem, $this>
     */
    public function usedInBomItems(): HasMany
    {
        return $this->hasMany(BomItem::class, 'component_part_id');
    }

    /**
     * Mamul kartıysa bağlı olduğu motor modeli.
     *
     * @return HasOne<MotorModel, $this>
     */
    public function motorModel(): HasOne
    {
        return $this->hasOne(MotorModel::class, 'finished_part_id');
    }

    public function isFinishedGood(): bool
    {
        return $this->type === PartType::FinishedGood;
    }

    /** Reçetesi olabilir mi? Yalnızca yarı mamul ve mamul. */
    public function canHaveBom(): bool
    {
        return in_array($this->type, [PartType::SemiFinished, PartType::FinishedGood], true);
    }

    /** Herhangi bir depoda kritik mi? Kural (depo bazlı): bakiye <= kritik seviye (takip varsa). */
    public function isCritical(): bool
    {
        return $this->stockBalances->contains(fn (StockBalance $b) => $b->isCriticalAt());
    }

    /** Kritik seviyedeki ürünler: en az bir depoda bakiye <= kritik seviye ve kritik seviye > 0. */
    public function scopeCritical(Builder $query): void
    {
        $query->whereHas('stockBalances', fn (Builder $balance) => $balance->criticalOnly());
    }

    /** Toplam bakiyesi > 0 olan ürünler. */
    public function scopeInStock(Builder $query): void
    {
        $query->whereRaw('(SELECT COALESCE(SUM(b.quantity), 0) FROM stock_balances b WHERE b.part_id = parts.id) > 0');
    }

    /**
     * Hareketsiz ürünler: toplam bakiyesi > 0 olan ve son $days gündür hiç hareket görmeyen ürünler.
     */
    public function scopeIdle(Builder $query, ?int $days = null): void
    {
        $since = now()->subDays($days ?? (int) config('stock.idle_days'));

        $query->inStock()
            ->whereDoesntHave('stockMovements', fn (Builder $movement) => $movement->where('created_at', '>=', $since));
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['code', 'name', 'unit_id', 'type', 'barcode', 'is_active', 'image_path', 'brand_id', 'description', 'default_warehouse_id', 'subtype_id'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
