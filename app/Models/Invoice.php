<?php

namespace App\Models;

use App\Enums\Currency;
use App\Enums\ExportType;
use App\Enums\Incoterm;
use App\Enums\InvoiceDirection;
use App\Enums\InvoiceKind;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\PackageType;
use App\Enums\TransportMode;
use App\Models\Concerns\HasOptimisticLocking;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
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
            'export_type' => ExportType::class,
            'deferred_vat_total' => 'decimal:2',
            'incoterm' => Incoterm::class,
            'transport_mode' => TransportMode::class,
            'package_count' => 'integer',
            'package_type' => PackageType::class,
            'gross_weight_kg' => 'decimal:3',
            'net_weight_kg' => 'decimal:3',
            'customs_declaration_date' => 'date',
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

    /**
     * İhraç kayıtlı satışta ihracatın yapılması gereken son gün (fatura tarihi + config accounting.registered_export_months).
     * Bu tarihe kadar ihracatçının gümrük beyannamesi işlenmezse tecil edilen KDV tahsil edilir.
     */
    public function exportDeadline(): ?CarbonImmutable
    {
        if ($this->export_type !== ExportType::RegisteredExport || $this->invoice_date === null) {
            return null;
        }

        return CarbonImmutable::parse($this->invoice_date)->addMonthsNoOverflow((int) config('accounting.registered_export_months'));
    }

    /** Kesilmiş, iptal edilmemiş, gümrük beyannamesi henüz işlenmemiş ihracat/ihraç kayıtlı faturalar. */
    public function scopeAwaitingCustomsDeclaration(Builder $query, ?ExportType $type = null): void
    {
        $query->where('status', InvoiceStatus::Issued->value)
            ->whereNull('customs_declaration_number')
            ->when($type, fn (Builder $q) => $q->where('export_type', $type->value), fn (Builder $q) => $q->whereNotNull('export_type'));
    }

    /** Beyannamesi gelmemiş ve süresi geçmiş ihraç kayıtlı satışlar. */
    public function scopeOverdueRegisteredExport(Builder $query): void
    {
        $months = (int) config('accounting.registered_export_months');

        $query->awaitingCustomsDeclaration(ExportType::RegisteredExport)
            ->whereRaw('invoice_date + make_interval(months => ?) < CURRENT_DATE', [$months]);
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
                'cancellation_reason', 'export_type', 'incoterm', 'transport_mode', 'package_count', 'package_type',
                'gross_weight_kg', 'net_weight_kg', 'customs_declaration_number', 'customs_declaration_date',
            ])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
