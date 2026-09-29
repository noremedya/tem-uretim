<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Customer;
use App\Support\TaxNumber;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Müşteri yönetimi. Kurallar (CLAUDE.md bölüm 5):
 * - Telefon zorunlu; vergi no ve vergi dairesi birlikte girilir.
 * - Aynı adla aktif bir müşteri varsa kayıt yalnızca açık onayla yapılır ($confirmDuplicateName).
 */
class CustomerService
{
    public const DUPLICATE_NAME_MESSAGE = 'Bu adla bir müşteri zaten var';

    public function create(array $data, bool $confirmDuplicateName = false): Customer
    {
        $customer = new Customer(Arr::only($this->normalize($data), Customer::FIELDS));
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
        if (blank($customer->phone)) {
            throw new BusinessRuleException('Telefon zorunludur.');
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

        foreach (['tax_office', 'phone', 'address'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = filled($data[$field]) ? trim($data[$field]) : null;
            }
        }

        return $data;
    }
}
