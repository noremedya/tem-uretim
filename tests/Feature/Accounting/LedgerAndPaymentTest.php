<?php

use App\Enums\AccountTransactionType;
use App\Enums\CashAccountKind;
use App\Enums\ChequeStatus;
use App\Enums\Currency;
use App\Enums\InvoiceType;
use App\Enums\PartType;
use App\Enums\PaymentStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\AccountTransaction;
use App\Models\CashAccount;
use App\Models\CashMovement;
use App\Models\Cheque;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Part;
use App\Models\Payment;
use App\Models\User;
use App\Services\ChequeService;
use App\Services\ExchangeRateService;
use App\Services\InvoiceService;
use App\Services\LedgerService;
use App\Services\PaymentService;
use App\Services\StatementService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->customer = Customer::factory()->create(['payment_term_days' => 30]);
    $this->supplier = Customer::factory()->create(['is_customer' => false, 'is_supplier' => true]);
    $this->ledger = app(LedgerService::class);
    $this->payments = app(PaymentService::class);
    $this->statements = app(StatementService::class);
    $this->cash = account(CashAccountKind::Cash, Currency::TRY);
    $this->bank = account(CashAccountKind::Bank, Currency::TRY);
});

function account(CashAccountKind $kind, Currency $currency): CashAccount
{
    $account = new CashAccount;
    $account->forceFill([
        'kind' => $kind, 'currency' => $currency, 'name' => $kind === CashAccountKind::Cash ? 'Kasa' : 'Vadesiz',
        'bank_name' => $kind === CashAccountKind::Bank ? 'Ziraat' : null, 'is_active' => true,
    ])->save();

    return $account;
}

/** Kesilmiş satış faturası ($amount KDV dahil toplam olsun diye KDV 0). */
function issuedSale(string $amount, string $date = '2026-09-01', ?Customer $customer = null, Currency $currency = Currency::TRY): Invoice
{
    $service = app(InvoiceService::class);
    $invoice = $service->createDraft(['type' => InvoiceType::SalesInvoice, 'customer_id' => ($customer ?? test()->customer)->id, 'invoice_date' => $date, 'currency' => $currency]);
    $service->addLine($invoice, ['description' => 'Motor', 'quantity' => 1, 'unit_price' => $amount, 'vat_rate' => 0]);

    return $service->issue($invoice, test()->admin);
}

function receive(array $data): Payment
{
    return app(PaymentService::class)->record(['direction' => 'received', 'customer_id' => test()->customer->id, 'method' => 'cash', 'cash_account_id' => test()->cash->id, 'currency' => 'TRY', 'payment_date' => '2026-10-10', ...$data], test()->admin);
}

it('kesilen fatura cariyi borçlandırır, iptal ters kaydeder; alış faturası alacaklandırır', function () {
    $sale = issuedSale('1000');
    expect($this->ledger->balances($this->customer))->toBe(['TRY' => '1000.00']);

    $tx = AccountTransaction::where('reference_type', 'invoice')->sole();
    expect($tx)->type->toBe(AccountTransactionType::Invoice)->due_date->toDateString()->toBe('2026-10-01');

    app(InvoiceService::class)->cancel($sale, $this->admin, 'Hata');
    expect($this->ledger->balances($this->customer))->toBe(['TRY' => '0.00']);

    $service = app(InvoiceService::class);
    $part = Part::factory()->create(['type' => PartType::RawMaterial]);
    $purchase = $service->createDraft(['type' => InvoiceType::PurchaseInvoice, 'customer_id' => $this->supplier->id, 'counterparty_number' => 'A-1']);
    $service->addLine($purchase, ['part_id' => $part->id, 'quantity' => 1, 'unit_price' => 500, 'vat_rate' => 20]);
    $service->issue($purchase, $this->admin);

    expect($this->ledger->balances($this->supplier))->toBe(['TRY' => '-600.00']);
});

it('nakit tahsilat: cari alacaklanır, kasa artar; iptal iki hareketi de ters kaydeder', function () {
    issuedSale('1000');
    $payment = receive(['amount' => '400']);

    expect($this->ledger->balances($this->customer))->toBe(['TRY' => '600.00'])
        ->and($this->ledger->cashBalance($this->cash))->toBe('400.00');

    expect(fn () => $this->payments->cancel($payment, $this->admin, ''))->toThrow(BusinessRuleException::class, 'zorunludur');
    $this->payments->cancel($payment, $this->admin, 'Yanlış cari');

    expect($payment->fresh()->status)->toBe(PaymentStatus::Cancelled)
        ->and($this->ledger->balances($this->customer))->toBe(['TRY' => '1000.00'])
        ->and($this->ledger->cashBalance($this->cash))->toBe('0.00');
});

it('hesap para birimi ve türü ödemeyle uyuşmalı', function () {
    $eurCash = account(CashAccountKind::Cash, Currency::EUR);

    expect(fn () => receive(['amount' => 10, 'cash_account_id' => $eurCash->id]))->toThrow(BusinessRuleException::class, 'para birimi ödemeninkiyle aynı')
        ->and(fn () => receive(['amount' => 10, 'method' => 'bank', 'cash_account_id' => $this->cash->id]))->toThrow(BusinessRuleException::class, 'banka hesabı seçin');
});

it('farklı para biriminde ödeme kurla cari para birimine çevrilir', function () {
    app(ExchangeRateService::class)->saveManual('EUR', '2026-10-09', '48', '50', $this->admin);
    issuedSale('100', '2026-10-09', currency: Currency::EUR);

    // 2.500 TL nakit, EUR cariye: 2.500 / 50 = 50 EUR.
    $payment = receive(['amount' => '2500', 'account_currency' => 'EUR']);

    expect($payment)->exchange_rate->toBe('0.020000')->account_amount->toBe('50.00')
        ->and($this->ledger->balances($this->customer))->toBe(['EUR' => '50.00'])
        ->and($this->ledger->cashBalance($this->cash))->toBe('2500.00');

    $manual = receive(['amount' => '1000', 'account_currency' => 'EUR', 'exchange_rate' => '0,0205']);
    expect($manual->account_amount)->toBe('20.50');
});

it('açık belgeler: FIFO vadesi en eski önce; elle bağlama önce uygulanır; vadesi geçenler', function () {
    $old = issuedSale('300', '2026-08-01');   // vade 31.08
    $mid = issuedSale('200', '2026-09-01');   // vade 01.10
    $new = issuedSale('500', '2026-10-05');   // vade 04.11

    receive(['amount' => '350']);
    $open = collect($this->statements->openItems($this->customer, Currency::TRY, Carbon::parse('2026-10-20')))
        ->mapWithKeys(fn ($i) => [$i['transaction']->reference_id => [$i['open'], $i['overdue']]]);
    expect($open->all())->toBe([$mid->id => ['150.00', true], $new->id => ['500.00', false]]);

    // Elle bağlama: 100 TL doğrudan en yeni faturaya.
    receive(['amount' => '100', 'allocations' => [['invoice_id' => $new->id, 'amount' => '100']]]);
    $open = collect($this->statements->openItems($this->customer, Currency::TRY, Carbon::parse('2026-10-20')))
        ->mapWithKeys(fn ($i) => [$i['transaction']->reference_id => $i['open']]);
    expect($open->all())->toBe([$mid->id => '150.00', $new->id => '400.00']);

    $overdue = $this->statements->overdueReceivables(Carbon::parse('2026-10-20'));
    expect($overdue)->toHaveCount(1)
        ->and($overdue[0]['overdue'])->toBe('150.00')
        ->and($overdue[0]['oldest_due']->toDateString())->toBe('2026-10-01');
});

it('elle bağlama kuralları: yanlış yön, fazla tutar', function () {
    $sale = issuedSale('100');

    expect(fn () => receive(['amount' => '50', 'allocations' => [['invoice_id' => $sale->id, 'amount' => '80']]]))->toThrow(BusinessRuleException::class, 'tutarını aşamaz')
        ->and(fn () => app(PaymentService::class)->record(['direction' => 'made', 'customer_id' => $this->customer->id, 'method' => 'cash', 'cash_account_id' => $this->cash->id, 'amount' => 10, 'allocations' => [['invoice_id' => $sale->id, 'amount' => 10]]], $this->admin))
        ->toThrow(BusinessRuleException::class, 'bağlanamaz');
});

describe('çek/senet', function () {
    it('alınan çek portföye girer, tahsil edilince bankaya geçer; tahsil edilmiş çekin tahsilatı iptal edilemez', function () {
        issuedSale('1000');
        $payment = receive(['method' => 'cheque', 'cash_account_id' => null, 'amount' => '1000', 'cheque_number' => 'CK-001', 'cheque_bank' => 'Garanti', 'cheque_drawer' => 'Akın Ltd.', 'cheque_due_date' => '2026-12-01']);

        $cheque = Cheque::sole();
        expect($cheque)->status->toBe(ChequeStatus::Portfolio)->amount->toBe('1000.00')
            ->and($this->ledger->balances($this->customer))->toBe(['TRY' => '0.00'])
            ->and(CashMovement::count())->toBe(0);

        app(ChequeService::class)->collect($cheque, $this->bank, '2026-12-01', $this->admin);
        expect($cheque->fresh()->status)->toBe(ChequeStatus::Collected)
            ->and($this->ledger->cashBalance($this->bank))->toBe('1000.00')
            ->and(fn () => $this->payments->cancel($payment, $this->admin, 'x'))->toThrow(BusinessRuleException::class, 'Tahsil edildi durumunda');
    });

    it('karşılıksız çıkan çek cariyi tekrar borçlandırır', function () {
        issuedSale('500');
        receive(['method' => 'note', 'cash_account_id' => null, 'amount' => '500', 'cheque_number' => 'SN-9', 'cheque_due_date' => '2026-11-01']);

        app(ChequeService::class)->bounce(Cheque::sole(), '2026-11-02', $this->admin, 'Ödenmedi');

        expect(Cheque::sole()->status)->toBe(ChequeStatus::Bounced)
            ->and($this->ledger->balances($this->customer))->toBe(['TRY' => '500.00'])
            ->and(AccountTransaction::where('type', AccountTransactionType::ChequeBounced)->sole()->due_date->toDateString())->toBe('2026-11-02');
    });

    it('ciro: portföydeki çekle tedarikçiye ödeme; ciro iptal edilince çek portföye döner', function () {
        receive(['method' => 'cheque', 'cash_account_id' => null, 'amount' => '750', 'cheque_number' => 'CK-2', 'cheque_bank' => 'Akbank', 'cheque_due_date' => '2026-12-15']);
        $cheque = Cheque::sole();

        $endorsement = app(PaymentService::class)->record(['direction' => 'made', 'customer_id' => $this->supplier->id, 'method' => 'endorsement', 'cheque_id' => $cheque->id], $this->admin);

        expect($endorsement)->amount->toBe('750.00')
            ->and($cheque->fresh()->status)->toBe(ChequeStatus::Endorsed)
            ->and($this->ledger->balances($this->supplier))->toBe(['TRY' => '750.00'])
            ->and(fn () => app(ChequeService::class)->collect($cheque->fresh(), $this->bank, today(), $this->admin))->toThrow(BusinessRuleException::class, 'portföydeki');

        $this->payments->cancel($endorsement, $this->admin, 'Yanlış tedarikçi');
        expect($cheque->fresh()->status)->toBe(ChequeStatus::Portfolio)
            ->and($this->ledger->balances($this->supplier))->toBe(['TRY' => '0.00']);
    });

    it('verilen çek ödemeyle oluşur, vadesinde ödendi olunca bankadan çıkar', function () {
        app(PaymentService::class)->record(['direction' => 'made', 'customer_id' => $this->supplier->id, 'method' => 'cheque', 'amount' => '300', 'cheque_number' => 'BIZ-1', 'cheque_bank' => 'Ziraat', 'cheque_due_date' => '2026-11-30'], $this->admin);
        $cheque = Cheque::sole();

        expect($cheque->direction->value)->toBe('issued')
            ->and($this->ledger->balances($this->supplier))->toBe(['TRY' => '300.00']);

        app(ChequeService::class)->pay($cheque, $this->bank, '2026-11-30', $this->admin);
        expect($this->ledger->cashBalance($this->bank))->toBe('-300.00')->and($cheque->fresh()->status)->toBe(ChequeStatus::Paid);
    });

    it('çek numarası, vadesi ve bankası zorunlu; ciro yalnızca ödemede', function () {
        expect(fn () => receive(['method' => 'cheque', 'cash_account_id' => null, 'amount' => 10, 'cheque_number' => '', 'cheque_due_date' => today()]))->toThrow(BusinessRuleException::class, 'numarası ve vadesi')
            ->and(fn () => receive(['method' => 'cheque', 'cash_account_id' => null, 'amount' => 10, 'cheque_number' => 'X', 'cheque_due_date' => today()]))->toThrow(BusinessRuleException::class, 'bankası zorunludur')
            ->and(fn () => receive(['method' => 'endorsement', 'cash_account_id' => null]))->toThrow(BusinessRuleException::class, 'yalnızca ödemede');
    });
});

it('açılış bakiyeleri cari/para birimi ve hesap başına tek seferlik', function () {
    $this->ledger->openCustomerBalance($this->customer, Currency::TRY, '-1.250,00', '2026-10-01', $this->admin);
    $this->ledger->openCashBalance($this->cash, '5000', '2026-10-01', $this->admin);

    expect($this->ledger->balances($this->customer))->toBe(['TRY' => '-1250.00'])
        ->and($this->ledger->cashBalance($this->cash))->toBe('5000.00')
        ->and(fn () => $this->ledger->openCustomerBalance($this->customer, Currency::TRY, '1', today(), $this->admin))->toThrow(BusinessRuleException::class, 'zaten girilmiş')
        ->and(fn () => $this->ledger->openCashBalance($this->cash, '1', today(), $this->admin))->toThrow(BusinessRuleException::class, 'zaten girilmiş');

    $this->ledger->openCustomerBalance($this->customer, Currency::EUR, '300', '2026-10-01', $this->admin);
    expect($this->ledger->balances($this->customer))->toBe(['EUR' => '300.00', 'TRY' => '-1250.00']);
});

it('cari ve kasa hareketleri değiştirilemez ve silinemez; ters kayıt tam ters olmalı', function () {
    issuedSale('100');
    $tx = AccountTransaction::sole();
    $movement = $this->ledger->openCashBalance($this->cash, '10', today(), $this->admin);

    expect(fn () => $tx->delete())->toThrow(BusinessRuleException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('account_transactions')->update(['amount' => 1])))->toThrow(QueryException::class, 'değiştirilemez')
        ->and(fn () => DB::transaction(fn () => DB::table('cash_movements')->delete()))->toThrow(QueryException::class, 'silinemez')
        ->and(fn () => DB::transaction(fn () => DB::table('account_transactions')->insert([
            'customer_id' => $this->customer->id, 'currency' => 'TRY', 'type' => 'correction', 'amount' => '-50',
            'transaction_date' => today(), 'corrected_transaction_id' => $tx->id, 'user_id' => $this->admin->id,
        ])))->toThrow(QueryException::class, 'tam tersi')
        ->and(fn () => DB::transaction(fn () => DB::table('cash_accounts')->insert(['kind' => 'cash', 'name' => 'İkinci', 'currency' => 'TRY', 'created_at' => now(), 'updated_at' => now()])))
        ->toThrow(QueryException::class, 'cash_accounts_one_cash_per_currency');
});
