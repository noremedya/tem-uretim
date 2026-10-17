<?php

namespace App\Models;

use App\Enums\Currency;
use App\Models\Concerns\HasOptimisticLocking;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Fillable(self::FIELDS)]
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory, HasOptimisticLocking, LogsActivity;

    public const FIELDS = [
        'name', 'tax_number', 'tax_office', 'phone', 'email', 'address',
        'is_customer', 'is_supplier', 'payment_term_days', 'default_currency',
    ];

    /** Veritabanı varsayılanlarıyla aynı (yeni kayıt kaydedilmeden önce de geçerli olsun). */
    protected $attributes = [
        'is_customer' => true,
        'is_supplier' => false,
        'payment_term_days' => 0,
        'default_currency' => 'TRY',
    ];

    /** Veritabanındaki customers_tax_number_format_check ile aynı kural: VKN (10) veya TCKN (11). */
    public const TAX_NUMBER_PATTERN = '/^[0-9]{10,11}$/';

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_customer' => 'boolean',
            'is_supplier' => 'boolean',
            'payment_term_days' => 'integer',
            'default_currency' => Currency::class,
        ];
    }

    /** Vergi no'daki boşluklar atılır: "123 456 7890" → "1234567890". Boşsa null. */
    public static function normalizeTaxNumber(?string $value): ?string
    {
        $value = preg_replace('/\s+/u', '', (string) $value);

        return $value !== '' ? $value : null;
    }

    protected function name(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => trim((string) $value));
    }

    protected function taxNumber(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => static::normalizeTaxNumber($value));
    }

    protected function email(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => filled($value) ? mb_strtolower(trim($value)) : null);
    }

    /** SQL'deki ad karşılaştırma ifadesi (customers_normalized_name_index ile aynı olmalı). */
    private const NORMALIZED_NAME_SQL = "translate(lower(btrim(%s)), 'ı', 'i')";

    /**
     * PHP tarafında ad karşılaştırması için NORMALIZED_NAME_SQL'in karşılığı: boşluklar kırpılır, küçük harfe
     * çevrilir; Türkçe I/İ/ı hepsi "i" sayılır (mb_strtolower('İ') "i̇" ürettiği için önce çevrilir).
     */
    public static function normalizeName(?string $name): string
    {
        return mb_strtolower(strtr(trim((string) $name), ['İ' => 'i', 'I' => 'i', 'ı' => 'i']));
    }

    /**
     * Aynı adlı aktif müşteriler: büyük/küçük harf (Türkçe I/ı dahil) ve baştaki/sondaki boşluklar önemsiz.
     * Karşılaştırma iki tarafta da aynı SQL ifadesiyle yapılır; index'i kullanır.
     */
    public function scopeActiveWithSameName(Builder $query, ?string $name, ?int $exceptId = null): void
    {
        $query->where('is_active', true)
            ->whereRaw(sprintf(self::NORMALIZED_NAME_SQL, 'name').' = '.sprintf(self::NORMALIZED_NAME_SQL, '?'), [(string) $name])
            ->when($exceptId !== null, fn (Builder $q) => $q->whereKeyNot($exceptId));
    }

    /** @return HasMany<Price, $this> */
    public function prices(): HasMany
    {
        return $this->hasMany(Price::class);
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([...self::FIELDS, 'is_active'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
