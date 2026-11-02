<?php

namespace App\Models;

use App\Exceptions\BusinessRuleException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Motor iadesi: eski sevk bilgisini saklar. Değiştirilemez, silinemez; yalnızca ProductionService yazar. */
class MotorUnitReturn extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['shipped_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new BusinessRuleException('Motor iade kayıtları değiştirilemez.'));
        static::deleting(fn () => throw new BusinessRuleException('Motor iade kayıtları silinemez.'));
    }

    /** @return BelongsTo<MotorUnit, $this> */
    public function motorUnit(): BelongsTo
    {
        return $this->belongsTo(MotorUnit::class);
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
