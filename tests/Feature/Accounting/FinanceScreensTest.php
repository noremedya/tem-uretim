<?php

use App\Enums\CashAccountKind;
use App\Enums\ChequeStatus;
use App\Enums\InvoiceType;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Filament\Pages\OverdueReceivables;
use App\Filament\Resources\CashAccounts\CashAccountResource;
use App\Filament\Resources\CashAccounts\Pages\CreateCashAccount;
use App\Filament\Resources\CashAccounts\Pages\ViewCashAccount;
use App\Filament\Resources\Cheques\ChequeResource;
use App\Filament\Resources\Cheques\Pages\ListCheques;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Filament\Resources\Customers\RelationManagers\AccountTransactionsRelationManager;
use App\Filament\Resources\Payments\Pages\CreatePayment;
use App\Filament\Resources\Payments\Pages\ViewPayment;
use App\Filament\Resources\Payments\PaymentResource;
use App\Models\CashAccount;
use App\Models\Cheque;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\User;
use App\Services\CashAccountService;
use App\Services\InvoiceService;
use App\Services\LedgerService;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(function () {
    $this->accountant = User::factory()->withRoles(Role::Accounting)->create();
    $this->customer = Customer::factory()->create(['name' => 'Akın Bobinaj', 'payment_term_days' => 0]);
    $this->cash = app(CashAccountService::class)->create(['kind' => 'cash', 'currency' => 'TRY', 'name' => 'Merkez kasa']);
    $this->bank = app(CashAccountService::class)->create(['kind' => 'bank', 'currency' => 'TRY', 'name' => 'Vadesiz', 'bank_name' => 'Ziraat', 'iban' => 'TR33 0006 1005 1978 6457 8413 26']);
    $this->actingAs($this->accountant);
});

function saleOf(string $amount, string $date): void
{
    $service = app(InvoiceService::class);
    $invoice = $service->createDraft(['type' => InvoiceType::SalesInvoice, 'customer_id' => test()->customer->id, 'invoice_date' => $date]);
    $service->addLine($invoice, ['description' => 'Motor', 'quantity' => 1, 'unit_price' => $amount, 'vat_rate' => 0]);
    $service->issue($invoice, test()->accountant);
}

it('banka hesabı açılır; IBAN normalize edilir; aynı para biriminde ikinci kasa açılamaz', function () {
    expect($this->bank->iban)->toBe('TR330006100519786457841326');

    Livewire::test(CreateCashAccount::class)
        ->fillForm(['kind' => 'cash', 'currency' => 'TRY', 'name' => 'İkinci kasa'])
        ->call('create')
        ->assertNotified('İşlem yapılamadı');

    Livewire::test(CreateCashAccount::class)
        ->fillForm(['kind' => 'bank', 'currency' => 'EUR', 'name' => 'Döviz', 'bank_name' => 'Garanti'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(CashAccount::where('kind', CashAccountKind::Bank)->count())->toBe(2);
});

it('kasa açılış bakiyesi girilir; bakiye listede ve sayfada görünür', function () {
    Livewire::test(ViewCashAccount::class, ['record' => $this->cash->getRouteKey()])
        ->callAction('opening', data: ['amount' => '12.500,00', 'date' => today()->toDateString()])
        ->assertNotified('Açılış bakiyesi girildi')
        ->assertActionHidden('opening');

    $this->get(CashAccountResource::getUrl('index'))->assertOk()->assertSee('12.500,00');
});

it('formdan nakit tahsilat kaydedilir ve iptal edilir', function () {
    saleOf('1000', today()->toDateString());

    Livewire::test(CreatePayment::class)
        ->fillForm([
            'direction' => 'received', 'customer_id' => $this->customer->id, 'payment_date' => today()->toDateString(),
            'method' => 'cash', 'cash_account_id' => $this->cash->id, 'amount' => '400', 'currency' => 'TRY', 'account_currency' => 'TRY',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $payment = Payment::sole();
    expect(app(LedgerService::class)->balances($this->customer))->toBe(['TRY' => '600.00']);

    Livewire::test(ViewPayment::class, ['record' => $payment->getRouteKey()])
        ->callAction('cancel', data: ['reason' => 'Yanlış kasa'])
        ->assertNotified('Kayıt iptal edildi')
        ->assertActionHidden('cancel');

    expect($payment->fresh()->status)->toBe(PaymentStatus::Cancelled);
});

it('çekle tahsilat portföye girer; listeden tahsil edilir ve karşılıksız işaretlenir', function () {
    $data = fn (string $number) => [
        'direction' => 'received', 'customer_id' => $this->customer->id, 'payment_date' => today()->toDateString(),
        'method' => 'cheque', 'amount' => '250', 'currency' => 'TRY', 'account_currency' => 'TRY',
        'cheque_number' => $number, 'cheque_bank' => 'Akbank', 'cheque_drawer' => 'Akın', 'cheque_due_date' => today()->addMonth()->toDateString(),
    ];
    Livewire::test(CreatePayment::class)->fillForm($data('CK-1'))->call('create')->assertHasNoFormErrors();
    Livewire::test(CreatePayment::class)->fillForm($data('CK-2'))->call('create')->assertHasNoFormErrors();
    [$first, $second] = Cheque::orderBy('id')->get()->all();

    Livewire::test(ListCheques::class)
        ->assertCanSeeTableRecords([$first, $second])
        ->callAction(TestAction::make('collect')->table($first), data: ['cash_account_id' => $this->bank->id, 'date' => today()->toDateString()])
        ->assertNotified('Tahsil edildi')
        ->callAction(TestAction::make('bounce')->table($second), data: ['date' => today()->toDateString()])
        ->assertNotified('Karşılıksız olarak işaretlendi');

    expect($first->fresh()->status)->toBe(ChequeStatus::Collected)
        ->and($second->fresh()->status)->toBe(ChequeStatus::Bounced)
        ->and(app(LedgerService::class)->cashBalance($this->bank))->toBe('250.00')
        ->and(app(LedgerService::class)->balances($this->customer))->toBe(['TRY' => '-250.00']);
});

it('cari sayfası ekstre, bakiye ve açık belgeleri gösterir; açılış bakiyesi girilir', function () {
    saleOf('300', '2026-08-01');

    Livewire::test(ViewCustomer::class, ['record' => $this->customer->getRouteKey()])
        ->callAction('opening', data: ['currency' => 'TRY', 'side' => 'credit', 'amount' => '100', 'date' => '2026-07-01'])
        ->assertNotified('Açılış bakiyesi girildi');

    $this->get(CustomerResource::getUrl('view', ['record' => $this->customer]))
        ->assertOk()
        ->assertSee('200,00 TRY — cari borçlu (alacağımız)')
        ->assertSee('VADESİ GEÇTİ');

    Livewire::test(AccountTransactionsRelationManager::class, ['ownerRecord' => $this->customer, 'pageClass' => ViewCustomer::class])
        ->assertCanSeeTableRecords($this->customer->accountTransactions)
        ->assertSee('Açılış bakiyesi (devir)');
});

it('vadesi geçmiş alacaklar sayfası', function () {
    saleOf('300', '2026-08-01');

    Livewire::test(OverdueReceivables::class)->assertSee('Akın Bobinaj')->assertSee('300,00 TRY');
});

it('depo ve operatör ön muhasebe ekranlarına erişemez', function (string $role) {
    $this->actingAs(User::factory()->{$role}()->create());

    $this->get(PaymentResource::getUrl('index'))->assertForbidden();
    $this->get(CashAccountResource::getUrl('index'))->assertForbidden();
    $this->get(ChequeResource::getUrl('index'))->assertForbidden();
    $this->get(OverdueReceivables::getUrl())->assertForbidden();
    expect(AccountTransactionsRelationManager::canViewForRecord($this->customer, ViewCustomer::class))->toBeFalse();
})->with(['warehouse', 'operator']);

it('ön muhasebe sayfaları açılır', function () {
    $this->get(PaymentResource::getUrl('index'))->assertOk();
    $this->get(PaymentResource::getUrl('create'))->assertOk();
    $this->get(CashAccountResource::getUrl('view', ['record' => $this->bank]))->assertOk();
    $this->get(ChequeResource::getUrl('index'))->assertOk();
    $this->get(OverdueReceivables::getUrl())->assertOk();
});
