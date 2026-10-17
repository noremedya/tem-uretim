<?php

namespace App\Models;

use App\Enums\Currency;
use App\Models\Concerns\HasOptimisticLocking;
use Database\Factories\PriceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Satış fiyatı: motor modelinin liste fiyatı (customer_id boş) veya müşteriye özel fiyatı. Yalnızca PriceService yazar.
 */
class Price extends Model
{
    /** @use HasFactory<PriceFactory> */
    use HasFactory, HasOptimisticLocking, LogsActivity;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'currency' => Currency::class,
            'unit_price' => 'decimal:2',
            'valid_from' => 'date',
        ];
    }

    /** @return BelongsTo<MotorModel, $this> */
    public function motorModel(): BelongsTo
    {
        return $this->belongsTo(MotorModel::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** Geçerliliği başlamamış (ileri tarihli) mı? Yalnızca bunlar düzenlenir veya silinir. */
    public function isFuture(): bool
    {
        return $this->valid_from->isAfter(today());
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['motor_model_id', 'customer_id', 'currency', 'unit_price', 'valid_from'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
