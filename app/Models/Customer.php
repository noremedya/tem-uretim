<?php

namespace App\Models;

use App\Models\Concerns\HasOptimisticLocking;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
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

    public const FIELDS = ['name', 'tax_number', 'tax_office', 'phone', 'email', 'address'];

    /** Veritabanındaki customers_tax_number_format_check ile aynı kural: VKN (10) veya TCKN (11). */
    public const TAX_NUMBER_PATTERN = '/^[0-9]{10,11}$/';

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
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
