<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Sipariş kalemi. Yalnızca OrderService üzerinden, sipariş açıkken değişir.
 */
#[Fillable(['motor_model_id', 'quantity'])]
class OrderItem extends Model
{
    use LogsActivity;

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<MotorModel, $this> */
    public function motorModel(): BelongsTo
    {
        return $this->belongsTo(MotorModel::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['order_id', 'motor_model_id', 'quantity'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
