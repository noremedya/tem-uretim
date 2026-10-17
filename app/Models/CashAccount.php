<?php

namespace App\Models;

use App\Enums\CashAccountKind;
use App\Enums\Currency;
use App\Models\Concerns\HasOptimisticLocking;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Kasa (para birimi başına bir tane) veya banka hesabı. Bakiye = hareketlerin toplamı.
 */
class CashAccount extends Model
{
    use HasOptimisticLocking, LogsActivity;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'kind' => CashAccountKind::class,
            'currency' => Currency::class,
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<CashMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(CashMovement::class);
    }

    public function label(): string
    {
        return $this->kind === CashAccountKind::Cash
            ? "{$this->name} ({$this->currency->value})"
            : "{$this->bank_name} — {$this->name} ({$this->currency->value})";
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['kind', 'name', 'currency', 'bank_name', 'branch', 'iban', 'is_active'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
