<?php

namespace App\Models;

use App\Enums\ChequeDirection;
use App\Enums\ChequeKind;
use App\Enums\ChequeStatus;
use App\Enums\Currency;
use App\Models\Concerns\HasOptimisticLocking;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Çek/senet. Alınanlar tahsilatla, verilenler ödemeyle oluşur; durumu ChequeService/PaymentService değiştirir.
 */
class Cheque extends Model
{
    use HasOptimisticLocking, LogsActivity;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'direction' => ChequeDirection::class,
            'kind' => ChequeKind::class,
            'status' => ChequeStatus::class,
            'currency' => Currency::class,
            'amount' => 'decimal:2',
            'due_date' => 'date',
        ];
    }

    /** Çeki/senedi veren (alınanda) veya alan (verilende) cari. @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<CashAccount, $this> */
    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class);
    }

    /** Çekin geçtiği tahsilat/ödemeler (alındığı tahsilat, ciro edildiği ödeme). @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function label(): string
    {
        return sprintf('%s %s — %s — vade %s', $this->kind->label(), $this->number, Money::format((string) $this->amount, $this->currency->value), $this->due_date->format('d.m.Y'));
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['direction', 'kind', 'customer_id', 'number', 'bank_name', 'drawer', 'due_date', 'amount', 'currency', 'status', 'cash_account_id'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
