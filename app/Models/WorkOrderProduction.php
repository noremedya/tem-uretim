<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * İş emrinin üretim kaydı (kısım kısım üretim): o kadar adet için sarf, fire, üretimden giriş ve motorlar. Silinmez,
 * değiştirilemez; yalnızca bir kez geri alınır (veritabanı trigger'ı).
 */
class WorkOrderProduction extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'reversed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<WorkOrder, $this> */
    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by_user_id');
    }

    /** @return BelongsToMany<StockMovement, $this> */
    public function movements(): BelongsToMany
    {
        return $this->belongsToMany(StockMovement::class, 'work_order_production_movements');
    }

    /** @return HasMany<MotorUnit, $this> */
    public function motorUnits(): HasMany
    {
        return $this->hasMany(MotorUnit::class);
    }

    public function isReversed(): bool
    {
        return $this->reversed_at !== null;
    }
}
