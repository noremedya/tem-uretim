<?php

namespace App\Models;

use App\Enums\Currency;
use App\Enums\PaymentDirection;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Concerns\HasOptimisticLocking;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Tahsilat veya ödeme. Kaydedilince cari ve kasa/banka hareketleri oluşur; değiştirilmez, açıklamayla iptal edilir.
 */
class Payment extends Model
{
    use HasOptimisticLocking, LogsActivity;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'direction' => PaymentDirection::class,
            'method' => PaymentMethod::class,
            'status' => PaymentStatus::class,
            'currency' => Currency::class,
            'account_currency' => Currency::class,
            'payment_date' => 'date',
            'amount' => 'decimal:2',
            'exchange_rate' => 'decimal:6',
            'account_amount' => 'decimal:2',
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<CashAccount, $this> */
    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class);
    }

    /** @return BelongsTo<Cheque, $this> */
    public function cheque(): BelongsTo
    {
        return $this->belongsTo(Cheque::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Elle bağlanan faturalar. @return BelongsToMany<Invoice, $this> */
    public function allocatedInvoices(): BelongsToMany
    {
        return $this->belongsToMany(Invoice::class, 'payment_allocations')->withPivot('amount');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['direction', 'customer_id', 'payment_date', 'method', 'cash_account_id', 'cheque_id', 'amount', 'currency', 'account_currency', 'exchange_rate', 'account_amount', 'status', 'cancellation_reason'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
