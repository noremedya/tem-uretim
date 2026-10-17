<?php

namespace App\Services;

use App\Enums\AccountTransactionType;
use App\Enums\CashMovementType;
use App\Enums\ChequeDirection;
use App\Enums\ChequeStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\CashAccount;
use App\Models\Cheque;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Portföydeki çek/senet işlemleri (CLAUDE.md bölüm 5 "Çek/senet"). Ciro PaymentService'tedir (ödeme yöntemi).
 */
class ChequeService
{
    public function __construct(private readonly LedgerService $ledger) {}

    /** Alınan çek/senedin tahsili: kasa/banka hesabına giriş. */
    public function collect(Cheque $cheque, CashAccount|int $account, mixed $date, User $user): Cheque
    {
        return $this->transition($cheque, ChequeDirection::Received, function (Cheque $cheque, CarbonImmutable $date) use ($account, $user): void {
            $account = $this->account($account, $cheque);
            $this->ledger->postCash($account, CashMovementType::ChequeCollected, (string) $cheque->amount, $date, $cheque, $user, "Tahsil: {$cheque->label()}");

            $cheque->status = ChequeStatus::Collected;
            $cheque->cash_account_id = $account->getKey();
        }, $date);
    }

    /** Alınan çek/senet karşılıksız: cari tekrar borçlanır. */
    public function bounce(Cheque $cheque, mixed $date, User $user, ?string $description = null): Cheque
    {
        return $this->transition($cheque, ChequeDirection::Received, function (Cheque $cheque, CarbonImmutable $date) use ($user, $description): void {
            $this->ledger->post(
                $cheque->customer_id,
                $cheque->currency,
                AccountTransactionType::ChequeBounced,
                (string) $cheque->amount,
                $date,
                $cheque,
                $user,
                trim("Karşılıksız: {$cheque->label()} ".($description ?? '')),
                dueDate: $date,
            );

            $cheque->status = ChequeStatus::Bounced;
        }, $date);
    }

    /** Verilen çek/senet vadesinde ödendi: kasa/banka hesabından çıkış. */
    public function pay(Cheque $cheque, CashAccount|int $account, mixed $date, User $user): Cheque
    {
        return $this->transition($cheque, ChequeDirection::Issued, function (Cheque $cheque, CarbonImmutable $date) use ($account, $user): void {
            $account = $this->account($account, $cheque);
            $this->ledger->postCash($account, CashMovementType::ChequePaid, bcmul((string) $cheque->amount, '-1', 2), $date, $cheque, $user, "Ödeme: {$cheque->label()}");

            $cheque->status = ChequeStatus::Paid;
            $cheque->cash_account_id = $account->getKey();
        }, $date);
    }

    private function transition(Cheque $cheque, ChequeDirection $direction, \Closure $apply, mixed $date): Cheque
    {
        return DB::transaction(function () use ($cheque, $direction, $apply, $date): Cheque {
            $locked = Cheque::query()->lockForUpdate()->findOrFail($cheque->getKey());

            if ($locked->direction !== $direction || $locked->status !== ChequeStatus::Portfolio) {
                throw new BusinessRuleException("Bu işlem yalnızca portföydeki {$direction->label()} çek/senette yapılır.");
            }

            $apply($locked, CarbonImmutable::parse($date ?? today())->startOfDay());
            $locked->save();

            $cheque->setRawAttributes($locked->getAttributes(), sync: true);

            return $cheque;
        });
    }

    private function account(CashAccount|int $account, Cheque $cheque): CashAccount
    {
        $account = $account instanceof CashAccount ? $account : (CashAccount::query()->find($account) ?? throw new BusinessRuleException('Kasa veya banka hesabı seçilmedi.'));

        if (! $account->is_active || $account->currency !== $cheque->currency) {
            throw new BusinessRuleException("Aktif ve {$cheque->currency->value} cinsinden bir kasa/banka hesabı seçin.");
        }

        return $account;
    }
}
