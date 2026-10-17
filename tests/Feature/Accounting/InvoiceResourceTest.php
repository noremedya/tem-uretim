<?php

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\PartType;
use App\Enums\Role;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Invoices\Pages\CreateInvoice;
use App\Filament\Resources\Invoices\Pages\EditInvoice;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Filament\Resources\Invoices\RelationManagers\LinesRelationManager;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\MotorUnit;
use App\Models\Order;
use App\Models\Part;
use App\Models\User;
use App\Services\ExchangeRateService;
use App\Services\InvoiceService;
use App\Services\ProductionService;
use App\Services\StockService;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Tests\Support\ProductionFixtures;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->accountant = User::factory()->withRoles(Role::Accounting)->create();
    $this->customer = Customer::factory()->create(['payment_term_days' => 15]);
    $this->supplier = Customer::factory()->create(['is_customer' => false, 'is_supplier' => true]);
});

function newDraft(InvoiceType $type, ?Customer $customer = null, array $data = []): Invoice
{
    return app(InvoiceService::class)->createDraft(['type' => $type, 'customer_id' => $customer->id, ...$data]);
}

it('muhasebe formdan satış faturası taslağı açar, motor ve elle satır ekler, keser', function () {
    $f = ProductionFixtures::create()->stockUp($this->admin);
    $order = Order::factory()->open()->withItem(2, $f->model)->create(['customer_id' => $this->customer->id]);
    $wo = $f->startedMotorWorkOrder($this->admin, 2, ['order_item_id' => $order->items()->sole()->id]);
    app(ProductionService::class)->complete($wo, $this->admin);
    $motors = MotorUnit::all();
    app(ProductionService::class)->ship($motors, $this->admin);
    $this->actingAs($this->accountant);

    Livewire::test(CreateInvoice::class)
        ->fillForm(['type' => InvoiceType::SalesInvoice->value, 'customer_id' => $this->customer->id, 'invoice_date' => today()->toDateString()])
        ->call('create')
        ->assertHasNoFormErrors();

    $invoice = Invoice::sole();
    expect($invoice->due_date->toDateString())->toBe(today()->addDays(15)->toDateString());

    $lines = Livewire::test(LinesRelationManager::class, ['ownerRecord' => $invoice, 'pageClass' => ViewInvoice::class])
        ->callAction(TestAction::make('addMotors')->table(), data: [
            'motor_model_id' => $f->model->id, 'motor_unit_ids' => $motors->pluck('id')->all(),
            'unit_price' => '10.000', 'discount_rate' => '15', 'vat_rate' => '20',
        ])
        ->assertHasNoFormErrors()
        ->callAction(TestAction::make('addLine')->table(), data: [
            'description' => 'Nakliye', 'quantity' => '1', 'unit' => 'Sefer', 'unit_price' => '500', 'discount_rate' => '0', 'vat_rate' => '20',
        ])
        ->assertHasNoFormErrors();

    $lines->assertSee($motors->first()->serial_number);

    // 2 × 10.000 − %15 = 17.000 + %20 KDV = 20.400; nakliye 500 + 100 = 600.
    expect($invoice->fresh()->grand_total)->toBe('21000.00');

    Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])
        ->callAction('issue')
        ->assertNotified('Satış faturası kesildi: FTR-'.now()->year.'-000001');

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Issued);

    Livewire::test(LinesRelationManager::class, ['ownerRecord' => $invoice->fresh(), 'pageClass' => ViewInvoice::class])
        ->assertActionHidden(TestAction::make('addLine')->table())
        ->assertActionHidden(TestAction::make('addMotors')->table());

    Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])
        ->assertActionHidden('edit')
        ->assertActionHidden('deleteDraft')
        ->callAction('cancel', data: ['reason' => 'Fiyat hatası'])
        ->assertNotified('Fatura iptal edildi');
});

it('alış faturası: malzeme satırı önceden yapılmış stok girişine bağlanır', function () {
    $part = Part::factory()->create(['code' => 'HM-SAC-X', 'type' => PartType::RawMaterial]);
    $receipt = app(StockService::class)->stockIn($part, '25', $this->admin, 'İrsaliye 55');
    $invoice = newDraft(InvoiceType::PurchaseInvoice, $this->supplier, ['counterparty_number' => 'SAC2026000000001']);
    $this->actingAs($this->accountant);

    Livewire::test(LinesRelationManager::class, ['ownerRecord' => $invoice, 'pageClass' => ViewInvoice::class])
        ->callAction(TestAction::make('addLine')->table(), data: [
            'part_id' => $part->id, 'quantity' => '25', 'unit_price' => '85,5', 'discount_rate' => '0', 'vat_rate' => '20',
            'stock_movement_ids' => [$receipt->id],
        ])
        ->assertHasNoFormErrors();

    Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])->callAction('issue')->assertNotified();

    expect((string) $part->stockBalance()->value('quantity'))->toBe('25.000')
        ->and($invoice->lines()->sole()->stockMovements()->sole()->id)->toBe($receipt->id);
});

it('kesme hatası bildirimle gösterilir (satırsız fatura)', function () {
    $invoice = newDraft(InvoiceType::SalesInvoice, $this->customer);
    $this->actingAs($this->accountant);

    Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])
        ->callAction('issue')
        ->assertNotified('İşlem yapılamadı');

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Draft);
});

it('taslak düzenlenir: para birimi değişince kur yeniden dolar; taslak silinir', function () {
    app(ExchangeRateService::class)->saveManual('EUR', today(), '48', '49', $this->admin);
    $invoice = newDraft(InvoiceType::SalesInvoice, $this->customer);
    $this->actingAs($this->accountant);

    Livewire::test(EditInvoice::class, ['record' => $invoice->getRouteKey()])
        ->assertFormFieldDisabled('type')
        ->fillForm(['currency' => 'EUR'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($invoice->fresh())->currency->value->toBe('EUR')->exchange_rate->toBe('49.000000');

    Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])
        ->callAction('deleteDraft')
        ->assertRedirect(InvoiceResource::getUrl('index'));

    expect(Invoice::count())->toBe(0);
});

it('depo yalnızca alış faturalarını salt okunur görür', function () {
    $sale = newDraft(InvoiceType::SalesInvoice, $this->customer);
    $purchase = newDraft(InvoiceType::PurchaseInvoice, $this->supplier);
    $this->actingAs(User::factory()->warehouse()->create());

    Livewire::test(ListInvoices::class)
        ->assertCanSeeTableRecords([$purchase])
        ->assertCanNotSeeTableRecords([$sale])
        ->assertActionHidden('create');

    $this->get(InvoiceResource::getUrl('view', ['record' => $purchase]))->assertOk();
    $this->get(InvoiceResource::getUrl('view', ['record' => $sale]))->assertNotFound();
    $this->get(InvoiceResource::getUrl('create'))->assertForbidden();

    Livewire::test(LinesRelationManager::class, ['ownerRecord' => $purchase, 'pageClass' => ViewInvoice::class])
        ->assertActionHidden(TestAction::make('addLine')->table());
});

it('operatör faturalara erişemez; sayfalar açılır', function () {
    $this->actingAs(User::factory()->operator()->create());
    $this->get(InvoiceResource::getUrl('index'))->assertForbidden();

    $this->actingAs($this->accountant);
    $invoice = newDraft(InvoiceType::SalesInvoice, $this->customer);
    $this->get(InvoiceResource::getUrl('index'))->assertOk();
    $this->get(InvoiceResource::getUrl('create'))->assertOk();
    $this->get(InvoiceResource::getUrl('view', ['record' => $invoice]))->assertOk();
    $this->get(InvoiceResource::getUrl('edit', ['record' => $invoice]))->assertOk();

    Livewire::test(ListInvoices::class)->assertCanSeeTableRecords([$invoice]);
});
