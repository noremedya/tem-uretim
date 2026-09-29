<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Models\Concerns\HasOptimisticLocking;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Sipariş. Numara, durum ve iptal açıklaması yalnızca OrderService tarafından atanır.
 */
#[Fillable(['customer_reference', 'customer_id', 'order_date', 'due_date', 'notes'])]
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory, HasOptimisticLocking, LogsActivity;

    protected $attributes = [
        'status' => 'open',
    ];

    protected function casts(): array
    {
        return [
            'order_date' => 'date',
            'due_date' => 'date',
            'status' => OrderStatus::class,
        ];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['order_number', 'customer_reference', 'customer_id', 'order_date', 'due_date', 'status', 'cancellation_reason', 'notes'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
