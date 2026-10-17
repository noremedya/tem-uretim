<?php

namespace App\Models;

use App\Enums\CashMovementType;
use App\Exceptions\BusinessRuleException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Kasa/banka hareketi: değiştirilemez, silinemez (burada ve trigger ile). Düzeltme ters kayıtla.
 */
class CashMovement extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'type' => CashMovementType::class,
            'amount' => 'decimal:2',
            'transaction_date' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new BusinessRuleException('Kasa/banka hareketleri değiştirilemez. Düzeltme için ters kayıt kullanın.'));
        static::deleting(fn () => throw new BusinessRuleException('Kasa/banka hareketleri silinemez. Düzeltme için ters kayıt kullanın.'));
    }

    /** @return BelongsTo<CashAccount, $this> */
    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class);
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

    /** @return HasOne<CashMovement, $this> */
    public function correction(): HasOne
    {
        return $this->hasOne(self::class, 'corrected_movement_id');
    }
}
