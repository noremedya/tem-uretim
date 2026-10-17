<?php

namespace App\Services;

use App\Enums\AccountTransactionType;
use App\Enums\CashMovementType;
use App\Enums\Currency;
use App\Exceptions\BusinessRuleException;
use App\Models\AccountTransaction;
use App\Models\CashAccount;
use App\Models\CashMovement;
use App\Models\Customer;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Cari hareketlerini ve kasa/banka hareketlerini yazan tek yer (CLAUDE.md bölüm 5 "Cari hesap hareketleri").
 * Hareketler değiştirilmez ve silinmez; düzeltme ters kayıtla yapılır. Bakiye = hareketlerin toplamı.
 * İşaret: cari hareketinde + cari borç (bizim alacağımız), − cari alacak; kasa/bankada + giriş, − çıkış.
 */
class LedgerService
{
    public function post(Customer|int $customer, Currency $currency, AccountTransactionType $type, string $amount, CarbonInterface $date, ?Model $reference, User $user, ?string $description = null, ?CarbonInterface $dueDate = null): AccountTransaction
    {
        $amount = Money::round($amount);

        if (Money::compare($amount, '0') === 0) {
            throw new BusinessRuleException('Tutar sıfır olamaz.');
        }

        $transaction = new AccountTransaction;
        $transaction->forceFill([
            'customer_id' => $customer instanceof Customer ? $customer->getKey() : $customer,
            'currency' => $currency,
            'type' => $type,
            'amount' => $amount,
            'transaction_date' => $date,
            'due_date' => $dueDate,
            'reference_type' => $reference?->getMorphClass(),
            'reference_id' => $reference?->getKey(),
            'description' => $description,
            'user_id' => $user->getKey(),
        ])->save();

        return $transaction;
    }

    /** Referansın henüz ters kaydedilmemiş cari hareketlerini ters kaydeder. */
    public function reverseFor(Model $reference, User $user, string $description, ?CarbonInterface $date = null): Collection
    {
        return AccountTransaction::query()
            ->where('reference_type', $reference->getMorphClass())
            ->where('reference_id', $reference->getKey())
            ->where('type', '!=', AccountTransactionType::Correction->value)
            ->whereDoesntHave('correction')
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->map(fn (AccountTransaction $t) => $this->reverse($t, $user, $description, $date));
    }

    public function reverse(AccountTransaction $original, User $user, string $description, ?CarbonInterface $date = null): AccountTransaction
    {
        try {
            $correction = new AccountTransaction;
            $correction->forceFill([
                'customer_id' => $original->customer_id,
                'currency' => $original->currency,
                'type' => AccountTransactionType::Correction,
                'amount' => bcmul((string) $original->amount, '-1', 2),
                'transaction_date' => $date ?? today(),
                'reference_type' => $original->reference_type,
                'reference_id' => $original->reference_id,
                'corrected_transaction_id' => $original->getKey(),
                'description' => $description,
                'user_id' => $user->getKey(),
            ])->save();
        } catch (UniqueConstraintViolationException) {
            throw new BusinessRuleException('Bu cari hareketi zaten ters kaydedilmiş.');
        }

        return $correction;
    }

    public function postCash(CashAccount $account, CashMovementType $type, string $amount, CarbonInterface $date, ?Model $reference, User $user, ?string $description = null): CashMovement
    {
        $amount = Money::round($amount);

        if (Money::compare($amount, '0') === 0) {
            throw new BusinessRuleException('Tutar sıfır olamaz.');
        }

        $movement = new CashMovement;
        $movement->forceFill([
            'cash_account_id' => $account->getKey(),
            'type' => $type,
            'amount' => $amount,
            'transaction_date' => $date,
            'reference_type' => $reference?->getMorphClass(),
            'reference_id' => $reference?->getKey(),
            'description' => $description,
            'user_id' => $user->getKey(),
        ])->save();

        return $movement;
    }

    /** Referansın henüz ters kaydedilmemiş kasa/banka hareketlerini ters kaydeder. */
    public function reverseCashFor(Model $reference, User $user, string $description, ?CarbonInterface $date = null): Collection
    {
        return CashMovement::query()
            ->where('reference_type', $reference->getMorphClass())
            ->where('reference_id', $reference->getKey())
            ->where('type', '!=', CashMovementType::Correction->value)
            ->whereDoesntHave('correction')
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->map(function (CashMovement $original) use ($user, $description, $date): CashMovement {
                $correction = new CashMovement;
                $correction->forceFill([
                    'cash_account_id' => $original->cash_account_id,
                    'type' => CashMovementType::Correction,
                    'amount' => bcmul((string) $original->amount, '-1', 2),
                    'transaction_date' => $date ?? today(),
                    'reference_type' => $original->reference_type,
                    'reference_id' => $original->reference_id,
                    'corrected_movement_id' => $original->getKey(),
                    'description' => $description,
                    'user_id' => $user->getKey(),
                ])->save();

                return $correction;
            });
    }

    /**
     * Carinin açılış (devir) bakiyesi: cari ve para birimi başına tek seferlik. + borç, − alacak.
     */
    public function openCustomerBalance(Customer $customer, Currency $currency, mixed $amount, mixed $date, User $user): AccountTransaction
    {
        $amount = Money::parse($amount ?? '');

        return DB::transaction(function () use ($customer, $currency, $amount, $date, $user): AccountTransaction {
            if ($customer->accountTransactions()->where('currency', $currency->value)->where('type', AccountTransactionType::Opening->value)->exists()) {
                throw new BusinessRuleException("Bu carinin {$currency->value} açılış bakiyesi zaten girilmiş.");
            }

            $date = CarbonImmutable::parse($date ?? today())->startOfDay();

            return $this->post($customer, $currency, AccountTransactionType::Opening, $amount, $date, null, $user, 'Açılış bakiyesi (devir)', dueDate: $date);
        });
    }

    /** Kasa/banka açılış bakiyesi: hesap başına tek seferlik. */
    public function openCashBalance(CashAccount $account, mixed $amount, mixed $date, User $user): CashMovement
    {
        $amount = Money::parse($amount ?? '');

        return DB::transaction(function () use ($account, $amount, $date, $user): CashMovement {
            if ($account->movements()->where('type', CashMovementType::Opening->value)->exists()) {
                throw new BusinessRuleException('Bu hesabın açılış bakiyesi zaten girilmiş.');
            }

            return $this->postCash($account, CashMovementType::Opening, $amount, CarbonImmutable::parse($date ?? today())->startOfDay(), null, $user, 'Açılış bakiyesi');
        });
    }

    /** @return array<string, string> para birimi => bakiye (yalnızca hareketi olanlar) */
    public function balances(Customer $customer): array
    {
        return $customer->accountTransactions()
            ->selectRaw('currency, SUM(amount) AS balance')
            ->groupBy('currency')
            ->orderBy('currency')
            ->pluck('balance', 'currency')
            ->map(fn ($balance) => Money::round((string) $balance))
            ->all();
    }

    public function cashBalance(CashAccount $account): string
    {
        return Money::round((string) $account->movements()->sum('amount'));
    }
}
