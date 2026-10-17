<?php

namespace App\Services;

use App\Enums\AccountTransactionType;
use App\Enums\CashAccountKind;
use App\Enums\CashMovementType;
use App\Enums\ChequeDirection;
use App\Enums\ChequeKind;
use App\Enums\ChequeStatus;
use App\Enums\Currency;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentDirection;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\CashAccount;
use App\Models\Cheque;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Tahsilat ve ödeme (CLAUDE.md bölüm 5 "Cari hesap hareketleri, tahsilat, ödeme").
 * Kayıt tek transaction'da: ödeme belgesi, cari hareketi, kasa/banka hareketi veya çek/senet, elle bağlamalar.
 */
class PaymentService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly ExchangeRateService $rates,
        private readonly InvoiceService $invoices,
    ) {}

    /**
     * @param  array{direction: PaymentDirection|string, customer_id: int, payment_date?: mixed, method: PaymentMethod|string, amount?: mixed, currency?: mixed, account_currency?: mixed, exchange_rate?: mixed, cash_account_id?: ?int, cheque_id?: ?int, cheque_number?: ?string, cheque_bank?: ?string, cheque_drawer?: ?string, cheque_due_date?: mixed, description?: ?string, allocations?: list<array{invoice_id: int, amount: mixed}>}  $data
     */
    public function record(array $data, User $user): Payment
    {
        $direction = $data['direction'] instanceof PaymentDirection ? $data['direction'] : PaymentDirection::from((string) $data['direction']);
        $method = $data['method'] instanceof PaymentMethod ? $data['method'] : PaymentMethod::from((string) $data['method']);

        if ($method === PaymentMethod::Endorsement && $direction !== PaymentDirection::Made) {
            throw new BusinessRuleException('Ciro yalnızca ödemede kullanılır (portföydeki çekle tedarikçiye ödeme).');
        }

        return DB::transaction(function () use ($direction, $method, $data, $user): Payment {
            $customer = Customer::query()->find($data['customer_id'] ?? 0) ?? throw new BusinessRuleException('Cari hesap seçilmedi.');

            if (! $customer->is_active) {
                throw new BusinessRuleException('Pasif cariye tahsilat/ödeme kaydedilemez.');
            }

            $date = CarbonImmutable::parse(filled($data['payment_date'] ?? null) ? $data['payment_date'] : today())->startOfDay();

            $payment = new Payment;
            $payment->forceFill([
                'direction' => $direction,
                'method' => $method,
                'customer_id' => $customer->getKey(),
                'payment_date' => $date,
                'status' => PaymentStatus::Posted,
                'user_id' => $user->getKey(),
                'description' => filled($data['description'] ?? null) ? trim($data['description']) : null,
            ]);

            $cheque = null;
            $account = null;

            if ($method === PaymentMethod::Endorsement) {
                // Ciro: tutar ve para birimi portföydeki çekten gelir.
                $cheque = Cheque::query()->lockForUpdate()->find($data['cheque_id'] ?? 0) ?? throw new BusinessRuleException('Ciro edilecek çek seçilmedi.');

                if ($cheque->direction !== ChequeDirection::Received || $cheque->status !== ChequeStatus::Portfolio) {
                    throw new BusinessRuleException('Yalnızca portföydeki alınan çek/senet ciro edilebilir.');
                }

                $payment->amount = (string) $cheque->amount;
                $payment->currency = $cheque->currency;
            } else {
                $payment->amount = $this->amount($data['amount'] ?? null);
                $payment->currency = $this->currency($data['currency'] ?? null) ?? $customer->default_currency;
            }

            $this->fillConversion($payment, $data, $date);

            if (in_array($method, [PaymentMethod::Cash, PaymentMethod::Bank], true)) {
                $account = $this->cashAccount($data['cash_account_id'] ?? null, $method, $payment->currency);
                $payment->cash_account_id = $account->getKey();
            } elseif ($method !== PaymentMethod::Endorsement) {
                $cheque = $this->newCheque($method, $direction, $customer, $payment, $data);
            }

            if ($cheque !== null) {
                $payment->cheque_id = $cheque->getKey();
            }

            $payment->save();

            if ($method === PaymentMethod::Endorsement) {
                $cheque->status = ChequeStatus::Endorsed;
                $cheque->save();
            }

            // Tahsilat cariyi alacaklandırır (−), ödeme borçlandırır (+).
            $this->ledger->post(
                $customer,
                $payment->account_currency,
                AccountTransactionType::Payment,
                $direction === PaymentDirection::Received ? bcmul((string) $payment->account_amount, '-1', 2) : (string) $payment->account_amount,
                $date,
                $payment,
                $user,
                $this->describe($payment, $cheque),
            );

            if ($account !== null) {
                $this->ledger->postCash(
                    $account,
                    CashMovementType::Payment,
                    $direction === PaymentDirection::Received ? (string) $payment->amount : bcmul((string) $payment->amount, '-1', 2),
                    $date,
                    $payment,
                    $user,
                    "{$direction->label()}: {$customer->name}",
                );
            }

            $this->allocate($payment, $data['allocations'] ?? []);

            return $payment;
        });
    }

    /**
     * İptal (açıklama zorunlu): cari ve kasa/banka hareketleri ters kaydedilir. Bu ödemeyle oluşan çek/senet iptal
     * edilir; ciroda çek portföye döner. Çeki tahsil edilmiş, ödenmiş, ciro edilmiş veya karşılıksız çıkmışsa reddedilir.
     */
    public function cancel(Payment $payment, User $user, ?string $reason): Payment
    {
        $reason = trim((string) $reason);

        if ($reason === '') {
            throw new BusinessRuleException('İptal açıklaması zorunludur.');
        }

        return DB::transaction(function () use ($payment, $user, $reason): Payment {
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->getKey());

            if ($locked->status !== PaymentStatus::Posted) {
                throw new BusinessRuleException('Bu kayıt zaten iptal edilmiş.');
            }

            if ($locked->cheque_id !== null) {
                $cheque = Cheque::query()->lockForUpdate()->findOrFail($locked->cheque_id);
                $expected = $locked->method === PaymentMethod::Endorsement ? ChequeStatus::Endorsed : ChequeStatus::Portfolio;

                if ($cheque->status !== $expected) {
                    throw new BusinessRuleException("Çek/senet {$cheque->status->label()} durumunda; bu kayıt iptal edilemez.");
                }

                $cheque->status = $locked->method === PaymentMethod::Endorsement ? ChequeStatus::Portfolio : ChequeStatus::Cancelled;
                $cheque->save();
            }

            $description = "İptal: {$reason}";
            $this->ledger->reverseFor($locked, $user, $description);
            $this->ledger->reverseCashFor($locked, $user, $description);

            $locked->status = PaymentStatus::Cancelled;
            $locked->cancelled_at = now();
            $locked->cancellation_reason = $reason;
            $locked->save();

            $payment->setRawAttributes($locked->getAttributes(), sync: true);

            return $payment;
        });
    }

    /** Ödeme para birimi cari para biriminden farklıysa ödeme tarihindeki kurla çevrilir (kur verilebilir). */
    private function fillConversion(Payment $payment, array $data, CarbonImmutable $date): void
    {
        $payment->account_currency = $this->currency($data['account_currency'] ?? null) ?? $payment->currency;

        if ($payment->account_currency === $payment->currency) {
            $payment->exchange_rate = '1.000000';
            $payment->account_amount = (string) $payment->amount;

            return;
        }

        if (filled($data['exchange_rate'] ?? null)) {
            $rate = Money::parse($data['exchange_rate'], Money::RATE_SCALE);
        } else {
            // Çapraz kur TL üzerinden: (ödeme/TL) ÷ (cari/TL).
            $rate = bcdiv($this->rates->rateFor($payment->currency, $date), $this->rates->rateFor($payment->account_currency, $date), Money::RATE_SCALE);
        }

        if (Money::compare($rate, '0') <= 0) {
            throw new BusinessRuleException('Kur sıfırdan büyük olmalıdır.');
        }

        $payment->exchange_rate = $rate;
        $payment->account_amount = Money::mul((string) $payment->amount, $rate);
    }

    private function cashAccount(mixed $id, PaymentMethod $method, Currency $currency): CashAccount
    {
        $account = CashAccount::query()->find($id ?? 0) ?? throw new BusinessRuleException('Kasa veya banka hesabı seçilmedi.');
        $kind = $method === PaymentMethod::Cash ? CashAccountKind::Cash : CashAccountKind::Bank;

        if ($account->kind !== $kind || ! $account->is_active) {
            throw new BusinessRuleException($method === PaymentMethod::Cash ? 'Nakit için aktif bir kasa seçin.' : 'Banka için aktif bir banka hesabı seçin.');
        }

        if ($account->currency !== $currency) {
            throw new BusinessRuleException("Seçilen hesap {$account->currency->value}; ödeme {$currency->value}. Hesabın para birimi ödemeninkiyle aynı olmalı.");
        }

        return $account;
    }

    private function newCheque(PaymentMethod $method, PaymentDirection $direction, Customer $customer, Payment $payment, array $data): Cheque
    {
        $number = trim((string) ($data['cheque_number'] ?? ''));

        if ($number === '' || blank($data['cheque_due_date'] ?? null)) {
            throw new BusinessRuleException('Çek/senet numarası ve vadesi zorunludur.');
        }

        if ($method === PaymentMethod::Cheque && blank($data['cheque_bank'] ?? null)) {
            throw new BusinessRuleException('Çekin bankası zorunludur.');
        }

        $cheque = new Cheque;
        $cheque->forceFill([
            'direction' => $direction === PaymentDirection::Received ? ChequeDirection::Received : ChequeDirection::Issued,
            'kind' => $method === PaymentMethod::Cheque ? ChequeKind::Cheque : ChequeKind::Note,
            'customer_id' => $customer->getKey(),
            'number' => $number,
            'bank_name' => filled($data['cheque_bank'] ?? null) ? trim($data['cheque_bank']) : null,
            'drawer' => filled($data['cheque_drawer'] ?? null) ? trim($data['cheque_drawer']) : null,
            'due_date' => CarbonImmutable::parse($data['cheque_due_date'])->startOfDay(),
            'amount' => (string) $payment->amount,
            'currency' => $payment->currency,
            'status' => ChequeStatus::Portfolio,
        ])->save();

        return $cheque;
    }

    /**
     * Elle faturaya bağlama: carinin kesilmiş, cari para birimindeki, ödemenin yönüne uygun faturaları
     * (tahsilat → satış faturası/alış iadesi, ödeme → alış faturası/satış iadesi). Toplam ödeme tutarını aşamaz.
     *
     * @param  list<array{invoice_id: int, amount: mixed}>  $allocations
     */
    private function allocate(Payment $payment, array $allocations): void
    {
        $total = '0';
        $wantedSign = $payment->direction === PaymentDirection::Received ? 1 : -1;

        foreach ($allocations as $allocation) {
            if (blank($allocation['invoice_id'] ?? null)) {
                continue;
            }

            $invoice = Invoice::query()->find($allocation['invoice_id']) ?? throw new BusinessRuleException('Bağlanacak fatura bulunamadı.');
            $amount = $this->amount($allocation['amount'] ?? null);

            if ($invoice->customer_id !== $payment->customer_id || $invoice->status !== InvoiceStatus::Issued
                || $invoice->currency !== $payment->account_currency || $this->invoices->receivableSign($invoice->type()) !== $wantedSign) {
                throw new BusinessRuleException("{$invoice->displayNumber()} bu {$payment->direction->label()} kaydına bağlanamaz (cari, para birimi veya belge türü uymuyor).");
            }

            $alreadyPinned = (string) DB::table('payment_allocations')
                ->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
                ->where('payment_allocations.invoice_id', $invoice->getKey())
                ->where('payments.status', PaymentStatus::Posted->value)
                ->sum('payment_allocations.amount');

            if (Money::compare(Money::add($alreadyPinned, $amount), (string) $invoice->grand_total) > 0) {
                throw new BusinessRuleException("{$invoice->displayNumber()} için bağlanan toplam fatura tutarını aşıyor.");
            }

            $total = Money::add($total, $amount);
            $payment->allocatedInvoices()->attach($invoice->getKey(), ['amount' => $amount]);
        }

        if (Money::compare($total, (string) $payment->account_amount) > 0) {
            throw new BusinessRuleException('Faturalara bağlanan toplam, tahsilat/ödeme tutarını aşamaz.');
        }
    }

    private function describe(Payment $payment, ?Cheque $cheque): string
    {
        $text = "{$payment->direction->label()} ({$payment->method->label()})";

        if ($cheque !== null) {
            $text .= " — {$cheque->kind->label()} {$cheque->number}";
        }

        if ($payment->currency !== $payment->account_currency) {
            $text .= sprintf(' — %s, kur %s', Money::format((string) $payment->amount, $payment->currency->value), Money::format((string) $payment->exchange_rate, scale: 4));
        }

        return $payment->description !== null ? "{$text} — {$payment->description}" : $text;
    }

    private function amount(mixed $value): string
    {
        $amount = Money::parse($value ?? '');

        if (Money::compare($amount, '0') <= 0) {
            throw new BusinessRuleException('Tutar sıfırdan büyük olmalıdır.');
        }

        return $amount;
    }

    private function currency(mixed $value): ?Currency
    {
        if (blank($value)) {
            return null;
        }

        return $value instanceof Currency ? $value : (Currency::tryFrom((string) $value) ?? throw new BusinessRuleException('Geçersiz para birimi.'));
    }
}
