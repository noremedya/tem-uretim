<?php

namespace App\Models;

use App\Enums\Currency;
use App\Enums\ExchangeRateSource;
use App\Models\Concerns\HasOptimisticLocking;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Döviz kuru (TCMB veya elle). Yalnızca ExchangeRateService yazar.
 */
class ExchangeRate extends Model
{
    use HasOptimisticLocking, LogsActivity;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'currency' => Currency::class,
            'rate_date' => 'date',
            'forex_buying' => 'decimal:6',
            'forex_selling' => 'decimal:6',
            'source' => ExchangeRateSource::class,
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Faturada kullanılan kur türüne göre kur (config accounting.invoice_rate_type). */
    public function invoiceRate(): string
    {
        return config('accounting.invoice_rate_type') === 'forex_buying'
            ? (string) $this->forex_buying
            : (string) $this->forex_selling;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['currency', 'rate_date', 'forex_buying', 'forex_selling', 'source', 'user_id'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
