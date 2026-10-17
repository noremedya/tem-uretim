<?php

namespace App\Models;

use App\Enums\AccountTransactionType;
use App\Enums\Currency;
use App\Exceptions\BusinessRuleException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Cari hareketi: + cari borç (bizim alacağımız), − cari alacak. Değiştirilemez, silinemez; düzeltme ters kayıtla.
 * Yalnızca LedgerService yazar.
 */
class AccountTransaction extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'type' => AccountTransactionType::class,
            'currency' => Currency::class,
            'amount' => 'decimal:2',
            'transaction_date' => 'date',
            'due_date' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new BusinessRuleException('Cari hareketleri değiştirilemez. Düzeltme için ters kayıt kullanın.'));
        static::deleting(fn () => throw new BusinessRuleException('Cari hareketleri silinemez. Düzeltme için ters kayıt kullanın.'));
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return HasOne<AccountTransaction, $this> */
    public function correction(): HasOne
    {
        return $this->hasOne(self::class, 'corrected_transaction_id');
    }
}
