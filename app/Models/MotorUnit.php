<?php

namespace App\Models;

use App\Enums\MotorUnitStatus;
use App\Exceptions\BusinessRuleException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Üretilen motor. İş emri tamamlanınca ProductionService oluşturur; silinmez (burada ve veritabanında trigger ile).
 */
class MotorUnit extends Model
{
    use LogsActivity;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'status' => MotorUnitStatus::class,
            'production_date' => 'date',
            'shipped_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new BusinessRuleException('Üretilen motor kayıtları silinemez.'));
    }

    /** @return BelongsTo<MotorModel, $this> */
    public function motorModel(): BelongsTo
    {
        return $this->belongsTo(MotorModel::class);
    }

    /** @return BelongsTo<WorkOrder, $this> */
    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    /** @return BelongsTo<ProductionBatch, $this> */
    public function productionBatch(): BelongsTo
    {
        return $this->belongsTo(ProductionBatch::class);
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<User, $this> */
    public function shippedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'shipped_by_user_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['serial_number', 'motor_model_id', 'work_order_id', 'order_id', 'status', 'shipped_at', 'shipped_by_user_id', 'notes'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
