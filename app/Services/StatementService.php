<?php

namespace App\Services;

use App\Enums\Currency;
use App\Enums\PaymentStatus;
use App\Models\AccountTransaction;
use App\Models\Customer;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Cari ekstre ve açık belgeler (CLAUDE.md bölüm 5 "Faturalarla eşleşme"): önce elle bağlamalar, sonra FIFO
 * (vadesi en eski belge önce). Her seferinde hareketlerden hesaplanır; saklanmaz.
 */
class StatementService
{
    /**
     * Ekstre: tarih sırasıyla hareketler ve yürüyen bakiye.
     *
     * @return list<array{transaction: AccountTransaction, balance: string}>
     */
    public function statement(Customer $customer, Currency $currency): array
    {
        $balance = '0.00';

        return $customer->accountTransactions()
            ->where('currency', $currency->value)
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get()
            ->map(function (AccountTransaction $t) use (&$balance): array {
                $balance = Money::add($balance, (string) $t->amount);

                return ['transaction' => $t, 'balance' => $balance];
            })
            ->all();
    }

    /**
     * Açık belgeler: borç/alacak doğuran hareketlerin (fatura, açılış, karşılıksız çek) kapanmamış kısmı.
     * Ters kaydı olan hareketler ikisi birden yok sayılır. Karşı yöndeki toplam tutar, önce elle bağlanmış
     * faturalara, kalanı vadesi en eski belgelerden başlayarak dağıtılır.
     *
     * @return list<array{transaction: AccountTransaction, open: string, overdue: bool}>
     */
    public function openItems(Customer $customer, Currency $currency, ?CarbonInterface $asOf = null): array
    {
        $asOf ??= today();

        $live = $customer->accountTransactions()
            ->where('currency', $currency->value)
            ->whereNull('corrected_transaction_id')
            ->whereDoesntHave('correction')
            ->orderBy('due_date')
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();

        $debits = $live->filter(fn (AccountTransaction $t) => Money::compare((string) $t->amount, '0') > 0)->values();
        $credits = $live->filter(fn (AccountTransaction $t) => Money::compare((string) $t->amount, '0') < 0)->values();

        $debitTotal = $debits->reduce(fn (string $c, AccountTransaction $t) => Money::add($c, (string) $t->amount), '0.00');
        $creditTotal = $credits->reduce(fn (string $c, AccountTransaction $t) => Money::add($c, bcmul((string) $t->amount, '-1', 2)), '0.00');

        // Büyük taraf açık kalır; küçük tarafın toplamı onun belgelerine dağıtılır.
        [$side, $available] = Money::compare($debitTotal, $creditTotal) >= 0
            ? [$debits, $creditTotal]
            : [$credits, $debitTotal];

        $open = $side->mapWithKeys(fn (AccountTransaction $t) => [$t->getKey() => Money::round(ltrim((string) $t->amount, '-'))])->all();

        // 1) Elle bağlamalar (yalnızca kayıtlı tahsilat/ödemeler, kesilmiş faturalar).
        foreach ($this->pins($customer, $currency) as $invoiceId => $pinned) {
            $transaction = $side->first(fn (AccountTransaction $t) => $t->reference_type === 'invoice' && $t->reference_id === $invoiceId);

            if ($transaction === null || Money::compare($available, '0') <= 0) {
                continue;
            }

            $take = $this->min([$pinned, $open[$transaction->getKey()], $available]);
            $open[$transaction->getKey()] = Money::sub($open[$transaction->getKey()], $take);
            $available = Money::sub($available, $take);
        }

        // 2) FIFO.
        foreach ($side as $transaction) {
            if (Money::compare($available, '0') <= 0) {
                break;
            }

            $take = $this->min([$open[$transaction->getKey()], $available]);
            $open[$transaction->getKey()] = Money::sub($open[$transaction->getKey()], $take);
            $available = Money::sub($available, $take);
        }

        return $side
            ->filter(fn (AccountTransaction $t) => Money::compare($open[$t->getKey()], '0') > 0)
            ->map(fn (AccountTransaction $t) => [
                'transaction' => $t,
                'open' => $open[$t->getKey()],
                'overdue' => $t->due_date !== null && $t->due_date->lt($asOf),
            ])
            ->values()
            ->all();
    }

    /**
     * Vadesi geçmiş alacaklar (cari borçlu taraf): cari ve para birimi başına toplam.
     *
     * @return list<array{customer: Customer, currency: Currency, overdue: string, oldest_due: CarbonInterface}>
     */
    public function overdueReceivables(?CarbonInterface $asOf = null): array
    {
        $asOf ??= today();
        $rows = [];

        $pairs = AccountTransaction::query()
            ->select('customer_id', 'currency')
            ->groupBy('customer_id', 'currency')
            ->havingRaw('SUM(amount) > 0')
            ->get();

        $customers = Customer::query()->findMany($pairs->pluck('customer_id'))->keyBy('id');

        foreach ($pairs as $pair) {
            $customer = $customers[$pair->customer_id];
            $currency = $pair->currency instanceof Currency ? $pair->currency : Currency::from($pair->currency);
            $items = collect($this->openItems($customer, $currency, $asOf))
                ->filter(fn (array $i) => $i['overdue'] && Money::compare((string) $i['transaction']->amount, '0') > 0);

            if ($items->isEmpty()) {
                continue;
            }

            $rows[] = [
                'customer' => $customer,
                'currency' => $currency,
                'overdue' => $items->reduce(fn (string $c, array $i) => Money::add($c, $i['open']), '0.00'),
                'oldest_due' => $items->min(fn (array $i) => $i['transaction']->due_date),
            ];
        }

        return $rows;
    }

    /** @return Collection<int, string> invoice_id => elle bağlanmış toplam */
    private function pins(Customer $customer, Currency $currency): Collection
    {
        return DB::table('payment_allocations')
            ->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->join('invoices', 'invoices.id', '=', 'payment_allocations.invoice_id')
            ->where('payments.customer_id', $customer->getKey())
            ->where('payments.account_currency', $currency->value)
            ->where('payments.status', PaymentStatus::Posted->value)
            ->where('invoices.status', 'issued')
            ->groupBy('payment_allocations.invoice_id')
            ->selectRaw('payment_allocations.invoice_id, SUM(payment_allocations.amount) AS pinned')
            ->pluck('pinned', 'invoice_id')
            ->map(fn ($v) => Money::round((string) $v));
    }

    /** @param list<string> $values */
    private function min(array $values): string
    {
        return array_reduce($values, fn (?string $min, string $v) => $min === null || Money::compare($v, $min) < 0 ? $v : $min);
    }
}
