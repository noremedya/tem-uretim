<?php

namespace App\Services;

use App\Enums\Currency;
use App\Exceptions\BusinessRuleException;
use App\Models\Customer;
use App\Support\Country;
use App\Support\TaxNumber;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Cari hesap (müşteri ve/veya tedarikçi) yönetimi. Kurallar (CLAUDE.md bölüm 5):
 * - Müşteri veya tedarikçi işaretlerinden en az biri seçilir; siparişi/fiyatı olan carinin müşteri işareti kaldırılamaz.
 * - Telefon zorunlu; vergi no ve vergi dairesi birlikte girilir.
 * - Aynı adla aktif bir müşteri varsa kayıt yalnızca açık onayla yapılır ($confirmDuplicateName).
 */
class CustomerService
{
    public const DUPLICATE_NAME_MESSAGE = 'Bu adla bir müşteri zaten var';

    public function create(array $data, bool $confirmDuplicateName = false): Customer
    {
        $customer = new Customer(Arr::only([
            'is_customer' => true,
            'is_supplier' => false,
            'payment_term_days' => 0,
            'default_currency' => Currency::TRY,
            ...$this->normalize($data),
        ], Customer::FIELDS));
        $customer->is_active = true;

        $this->ensureValid($customer, $confirmDuplicateName);
        $customer->save();

        return $customer;
    }

    /**
     * @param  int  $expectedLockVersion  Düzenlemenin başladığı andaki lock_version.
     */
    public function update(Customer $customer, array $data, int $expectedLockVersion, bool $confirmDuplicateName = false): Customer
    {
        $data = $this->normalize($data);

        return DB::transaction(function () use ($customer, $data, $expectedLockVersion, $confirmDuplicateName): Customer {
            $customer->fill(Arr::only($data, Customer::FIELDS));

            $this->ensureValid($customer, $confirmDuplicateName);
            $customer->saveExpectingVersion($expectedLockVersion);

            return $customer;
        });
    }

    /** Pasif müşteriye yeni sipariş açılamaz; mevcut siparişleri korunur. */
    public function deactivate(Customer $customer): Customer
    {
        $customer->is_active = false;
        $customer->save();

        return $customer;
    }

    public function activate(Customer $customer): Customer
    {
        $customer->is_active = true;
        $customer->save();

        return $customer;
    }

    /**
     * Aynı adlı aktif müşteriler (uyarı için). Düzenlemede yalnızca ad değiştiyse bakılır; kaydın kendisi sayılmaz.
     *
     * @return Collection<int, Customer>
     */
    public function duplicatesByName(?string $name, ?Customer $customer = null): Collection
    {
        if (blank($name) || ($customer?->exists && ! $this->nameChanged($customer, $name))) {
            return collect();
        }

        return Customer::query()->activeWithSameName($name, $customer?->getKey())->orderBy('id')->get();
    }

    /** Değişiklik yalnızca büyük/küçük harf veya boşluksa ad değişmemiş sayılır. */
    private function nameChanged(Customer $customer, string $name): bool
    {
        return Customer::normalizeName($name) !== Customer::normalizeName($customer->getOriginal('name'));
    }

    private function ensureValid(Customer $customer, bool $confirmDuplicateName): void
    {
        if (! $customer->is_customer && ! $customer->is_supplier) {
            throw new BusinessRuleException('Cari hesap müşteri, tedarikçi veya ikisi birden olmalıdır.');
        }

        if ($customer->exists && $customer->isDirty('is_customer') && ! $customer->is_customer
            && ($customer->orders()->exists() || $customer->prices()->exists())) {
            throw new BusinessRuleException('Siparişi veya müşteri fiyatı olan carinin müşteri işareti kaldırılamaz.');
        }

        if ($customer->payment_term_days < 0 || $customer->payment_term_days > 365) {
            throw new BusinessRuleException('Vade günü 0 ile 365 arasında olmalıdır.');
        }

        if (blank($customer->phone)) {
            throw new BusinessRuleException('Telefon zorunludur.');
        }

        if (! $customer->isDomestic() && ($customer->tax_number !== null || $customer->tax_office !== null)) {
            throw new BusinessRuleException('Yurt dışındaki cariye Türk vergi no ve vergi dairesi girilmez; yabancı vergi numarası alanını kullanın.');
        }

        if ($customer->isDomestic() && $customer->foreign_tax_id !== null) {
            throw new BusinessRuleException('Yabancı vergi numarası yalnızca yurt dışındaki cariye girilir.');
        }

        if (($customer->tax_number === null) !== ($customer->tax_office === null)) {
            throw new BusinessRuleException('Vergi no ve vergi dairesi birlikte girilmelidir.');
        }

        if (! $confirmDuplicateName && $this->duplicatesByName($customer->name, $customer)->isNotEmpty()) {
            throw new BusinessRuleException(self::DUPLICATE_NAME_MESSAGE.'. Aynı adla kaydetmek için onaylayın.');
        }
    }

    private function normalize(array $data): array
    {
        if (array_key_exists('tax_number', $data)) {
            $data['tax_number'] = Customer::normalizeTaxNumber($data['tax_number']);

            if ($data['tax_number'] !== null && ! preg_match(Customer::TAX_NUMBER_PATTERN, $data['tax_number'])) {
                throw new BusinessRuleException('Vergi no 10 (VKN) veya 11 (TCKN) haneli olmalı ve yalnızca rakam içermelidir.');
            }

            if ($data['tax_number'] !== null && ! TaxNumber::isValid($data['tax_number'])) {
                throw new BusinessRuleException(TaxNumber::INVALID_MESSAGE);
            }
        }

        if (array_key_exists('payment_term_days', $data)) {
            $days = filled($data['payment_term_days']) ? $data['payment_term_days'] : 0;

            if (filter_var($days, FILTER_VALIDATE_INT) === false) {
                throw new BusinessRuleException('Vade günü tam sayı olmalıdır.');
            }

            $data['payment_term_days'] = (int) $days;
        }

        if (array_key_exists('default_currency', $data)) {
            $data['default_currency'] = $data['default_currency'] instanceof Currency
                ? $data['default_currency']
                : (Currency::tryFrom((string) $data['default_currency']) ?? throw new BusinessRuleException('Geçersiz para birimi.'));
        }

        foreach (['is_customer', 'is_supplier'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = (bool) $data[$field];
            }
        }

        if (array_key_exists('country_code', $data)) {
            $data['country_code'] = filled($data['country_code']) ? mb_strtoupper(trim($data['country_code'])) : Country::TURKEY;

            if (! Country::isValid($data['country_code'])) {
                throw new BusinessRuleException('Geçersiz ülke.');
            }
        }

        foreach (['tax_office', 'phone', 'address', 'foreign_tax_id'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = filled($data[$field]) ? trim($data[$field]) : null;
            }
        }

        return $data;
    }
}
