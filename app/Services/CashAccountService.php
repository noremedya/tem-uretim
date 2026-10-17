<?php

namespace App\Services;

use App\Enums\CashAccountKind;
use App\Enums\Currency;
use App\Exceptions\BusinessRuleException;
use App\Models\CashAccount;
use Illuminate\Support\Facades\DB;

/**
 * Kasa (para birimi başına bir tane) ve banka hesapları. Türü ve para birimi oluşturulduktan sonra değişmez.
 */
class CashAccountService
{
    public function create(array $data): CashAccount
    {
        $account = new CashAccount;
        $account->kind = $data['kind'] instanceof CashAccountKind ? $data['kind'] : CashAccountKind::from((string) $data['kind']);
        $account->currency = $data['currency'] instanceof Currency ? $data['currency'] : Currency::from((string) $data['currency']);
        $account->is_active = true;

        if ($account->kind === CashAccountKind::Cash && CashAccount::query()->where('kind', 'cash')->where('currency', $account->currency->value)->exists()) {
            throw new BusinessRuleException("{$account->currency->value} kasası zaten var; para birimi başına bir kasa olur.");
        }

        $this->fill($account, $data);
        $account->save();

        return $account;
    }

    /**
     * @param  int  $expectedLockVersion  Düzenlemenin başladığı andaki lock_version.
     */
    public function update(CashAccount $account, array $data, int $expectedLockVersion): CashAccount
    {
        return DB::transaction(function () use ($account, $data, $expectedLockVersion): CashAccount {
            $this->fill($account, $data);

            if (array_key_exists('is_active', $data)) {
                $account->is_active = (bool) $data['is_active'];
            }

            $account->saveExpectingVersion($expectedLockVersion);

            return $account;
        });
    }

    private function fill(CashAccount $account, array $data): void
    {
        foreach (['name', 'bank_name', 'branch', 'iban'] as $field) {
            if (array_key_exists($field, $data)) {
                $value = filled($data[$field]) ? trim($data[$field]) : null;
                $account->{$field} = $field === 'iban' && $value !== null ? strtoupper(str_replace(' ', '', $value)) : $value;
            }
        }

        if (blank($account->name)) {
            $account->name = $account->kind === CashAccountKind::Cash ? 'Kasa' : '';
        }

        if ($account->kind === CashAccountKind::Bank && (blank($account->name) || blank($account->bank_name))) {
            throw new BusinessRuleException('Banka hesabında hesap adı ve banka adı zorunludur.');
        }

        if ($account->kind === CashAccountKind::Cash) {
            $account->bank_name = $account->branch = $account->iban = null;
        }

        if ($account->iban !== null && ! preg_match('/^TR\d{24}$/', $account->iban)) {
            throw new BusinessRuleException('IBAN "TR" ile başlayan 26 karakter olmalıdır.');
        }

        if ($account->iban !== null && CashAccount::query()->where('iban', $account->iban)->when($account->exists, fn ($q) => $q->whereKeyNot($account->getKey()))->exists()) {
            throw new BusinessRuleException('Bu IBAN başka bir hesapta kayıtlı.');
        }
    }
}
