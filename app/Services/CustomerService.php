<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Customer;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class CustomerService
{
    public function create(array $data): Customer
    {
        $customer = new Customer(Arr::only($this->normalize($data), Customer::FIELDS));
        $customer->is_active = true;
        $customer->save();

        return $customer;
    }

    /**
     * @param  int  $expectedLockVersion  Düzenlemenin başladığı andaki lock_version.
     */
    public function update(Customer $customer, array $data, int $expectedLockVersion): Customer
    {
        $data = $this->normalize($data);

        return DB::transaction(function () use ($customer, $data, $expectedLockVersion): Customer {
            $customer->fill(Arr::only($data, Customer::FIELDS));
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

    private function normalize(array $data): array
    {
        if (array_key_exists('tax_number', $data)) {
            $data['tax_number'] = Customer::normalizeTaxNumber($data['tax_number']);

            if ($data['tax_number'] !== null && ! preg_match(Customer::TAX_NUMBER_PATTERN, $data['tax_number'])) {
                throw new BusinessRuleException('Vergi no 10 (VKN) veya 11 (TCKN) haneli olmalı ve yalnızca rakam içermelidir.');
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
