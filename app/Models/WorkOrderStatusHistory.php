<?php

namespace App\Models;

use App\Enums\WorkOrderStatus;
use App\Exceptions\BusinessRuleException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * İş emri durum geçmişi. Değiştirilemez ve silinemez (burada ve veritabanında trigger ile).
 */
class WorkOrderStatusHistory extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'from_status' => WorkOrderStatus::class,
            'to_status' => WorkOrderStatus::class,
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new BusinessRuleException('İş emri durum geçmişi değiştirilemez.'));
        static::deleting(fn () => throw new BusinessRuleException('İş emri durum geçmişi silinemez.'));
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
}
