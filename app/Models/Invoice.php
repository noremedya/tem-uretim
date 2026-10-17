<?php

namespace App\Models;

use App\Enums\Currency;
use App\Enums\InvoiceDirection;
use App\Enums\InvoiceKind;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\Concerns\HasOptimisticLocking;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Fatura (satış/alış, fatura/iade). Yalnızca InvoiceService değiştirir; kesildikten sonra kilitlidir
 * (veritabanı trigger'ıyla da).
 */
class Invoice extends Model
{
    use HasOptimisticLocking, LogsActivity;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'direction' => InvoiceDirection::class,
            'kind' => InvoiceKind::class,
            'status' => InvoiceStatus::class,
            'currency' => Currency::class,
            'invoice_date' => 'date',
            'due_date' => 'date',
            'exchange_rate' => 'decimal:6',
            'subtotal' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'vat_total' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'grand_total_try' => 'decimal:2',
            'issued_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function type(): InvoiceType
    {
        return InvoiceType::of($this->direction, $this->kind);
    }

    public function isDraft(): bool
    {
        return $this->status === InvoiceStatus::Draft;
    }

    /** Ekranda gösterilen belge no: bizim numaramız veya karşı tarafınki; taslakta "Taslak #id". */
    public function displayNumber(): string
    {
        return $this->invoice_number ?? $this->counterparty_number ?? "Taslak #{$this->getKey()}";
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<Invoice, $this> */
    public function originalInvoice(): BelongsTo
    {
        return $this->belongsTo(self::class, 'original_invoice_id');
    }

    /** @return BelongsTo<User, $this> */
    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_user_id');
    }

    /** @return HasMany<InvoiceLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'direction', 'kind', 'status', 'customer_id', 'invoice_number', 'counterparty_number', 'original_invoice_id',
                'invoice_date', 'due_date', 'currency', 'exchange_rate', 'grand_total', 'grand_total_try', 'notes',
                'cancellation_reason',
            ])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
