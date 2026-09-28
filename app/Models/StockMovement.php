<?php

namespace App\Models;

use App\Enums\StockMovementType;
use App\Exceptions\BusinessRuleException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Stok hareketi. Yalnızca StockService oluşturur; değiştirilemez ve silinemez
 * (burada ve veritabanında trigger ile). Hata düzeltme ters kayıtla yapılır.
 */
class StockMovement extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'type' => StockMovementType::class,
            'quantity' => 'decimal:3',
            'balance_before' => 'decimal:3',
            'balance_after' => 'decimal:3',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new BusinessRuleException('Stok hareketleri değiştirilemez. Düzeltme için ters kayıt kullanın.'));
        static::deleting(fn () => throw new BusinessRuleException('Stok hareketleri silinemez. Düzeltme için ters kayıt kullanın.'));
    }

    /** @return BelongsTo<Part, $this> */
    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Bu hareket bir ters kayıtsa, ters kaydettiği hareket.
     *
     * @return BelongsTo<StockMovement, $this>
     */
    public function correctedMovement(): BelongsTo
    {
        return $this->belongsTo(self::class, 'corrected_movement_id');
    }

    /**
     * Bu hareketin ters kaydı (varsa).
     *
     * @return HasOne<StockMovement, $this>
     */
    public function correction(): HasOne
    {
        return $this->hasOne(self::class, 'corrected_movement_id');
    }

    /**
     * Türü gereği ters kaydedilebilir mi? Ters kaydın kendisi ters kaydedilemez. Üretim sarfı üretim
     * akışına bağlı olduğu için elle ters kaydedilmez. (Daha önce ters kaydedilmiş olması ayrıca kontrol edilir.)
     */
    public function isCorrectableType(): bool
    {
        return ! in_array($this->type, [StockMovementType::Correction, StockMovementType::ProductionConsumption], true);
    }
}
