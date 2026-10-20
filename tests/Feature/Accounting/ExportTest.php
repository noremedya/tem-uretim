<?php

use App\Enums\AccountTransactionType;
use App\Enums\ExportType;
use App\Enums\Incoterm;
use App\Enums\InvoiceType;
use App\Enums\PackageType;
use App\Enums\TransportMode;
use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\Customers\Pages\CreateCustomer;
use App\Filament\Resources\Invoices\Pages\CreateInvoice;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Models\AccountTransaction;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\MotorUnit;
use App\Models\Order;
use App\Models\User;
use App\Services\CustomerService;
use App\Services\InvoiceService;
use App\Services\MotorModelService;
use App\Services\ProductionService;
use Database\Factories\CustomerFactory;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\ProductionFixtures;

/*
 * Aşama 5e — İhracat (CLAUDE.md bölüm 5 "İhracat"): doğrudan ihracat (KDV 11/1-a, %0) ve ihraç kayıtlı satış
 * (KDV 11/1-c, KDV hesaplanır ve tecil edilir).
 */

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
    $this->service = app(InvoiceService::class);
    $this->foreign = Customer::factory()->foreign('GR')->create(['payment_term_days' => 30]);
    $this->exporter = Customer::factory()->create(['name' => 'Dış Ticaret A.Ş.']);
    $this->f = ProductionFixtures::create()->stockUp($this->admin);
    $model = $this->f->model->fresh();
    app(MotorModelService::class)->update($model, ['gtip_code' => '8501.52.20.00.00', 'net_weight_kg' => '8,5'], $model->lock_version);
});

/** Üretilip sevk edilmiş, $customer'ın siparişine bağlı motorlar. */
function exportMotors(int $count, Customer $customer): array
{
    $t = test();
    $order = Order::factory()->open()->withItem($count, $t->f->model)->create(['customer_id' => $customer->id]);
    $wo = $t->f->startedMotorWorkOrder($t->admin, $count, ['order_item_id' => $order->items()->sole()->id]);
    app(ProductionService::class)->complete($wo, $t->admin);
    $units = MotorUnit::where('work_order_id', $wo->id)->orderBy('id')->get();
    app(ProductionService::class)->ship($units, $t->admin);

    return $units->all();
}

function exportDraft(Customer $customer, ExportType $type, array $data = []): Invoice
{
    return app(InvoiceService::class)->createDraft([
        'type' => InvoiceType::SalesInvoice, 'customer_id' => $customer->id, 'export_type' => $type, 'exchange_rate' => '48,5', ...$data,
    ]);
}

function shippingDetails(): array
{
    return [
        'incoterm' => Incoterm::FOB, 'transport_mode' => TransportMode::Sea, 'package_count' => 2,
        'package_type' => PackageType::Pallet, 'gross_weight_kg' => '40',
    ];
}

// --- Cari: ülke ve vergi alanları ----------------------------------------------------------------------------

it('yurt dışındaki cariye Türk vergi no girilmez, yurt içindekine yabancı vergi no girilmez (servis ve veritabanı)', function () {
    $service = app(CustomerService::class);

    expect(fn () => $service->create(['name' => 'Hellas', 'phone' => '1', 'country_code' => 'gr', 'tax_number' => CustomerFactory::validVkn(), 'tax_office' => 'X']))
        ->toThrow(BusinessRuleException::class, 'Türk vergi no');
    expect(fn () => $service->create(['name' => 'Yerli', 'phone' => '1', 'country_code' => 'TR', 'foreign_tax_id' => 'EL1']))
        ->toThrow(BusinessRuleException::class, 'yurt dışındaki');
    expect(fn () => $service->create(['name' => 'X', 'phone' => '1', 'country_code' => 'QQ']))
        ->toThrow(BusinessRuleException::class, 'Geçersiz ülke');

    $hellas = $service->create(['name' => 'Hellas Pumps S.A.', 'phone' => '+30 2310 000000', 'country_code' => 'gr', 'foreign_tax_id' => ' EL094019245 ']);
    expect($hellas)->country_code->toBe('GR')->foreign_tax_id->toBe('EL094019245')->isDomestic()->toBeFalse();

    expect(fn () => DB::transaction(fn () => DB::table('customers')->where('id', $hellas->id)->update(['tax_number' => '1234567890', 'tax_office' => 'X'])))
        ->toThrow(QueryException::class, 'customers_domestic_tax_check');
    expect(fn () => DB::transaction(fn () => DB::table('customers')->where('id', $this->exporter->id)->update(['foreign_tax_id' => 'X'])))
        ->toThrow(QueryException::class, 'customers_foreign_tax_check');
});

it('cari formunda ülke seçilince ilgili vergi alanları görünür', function () {
    Livewire::test(CreateCustomer::class)
        ->fillForm(['name' => 'Bulgar Motor OOD', 'phone' => '+359 2 000 0000', 'country_code' => 'BG', 'foreign_tax_id' => 'BG123456789'])
        ->assertFormFieldHidden('tax_number')
        ->assertFormFieldVisible('foreign_tax_id')
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Customer::query()->where('name', 'Bulgar Motor OOD')->sole())
        ->country_code->toBe('BG')->tax_number->toBeNull()->foreign_tax_id->toBe('BG123456789');
});

// --- Motor modeli: GTİP ve ağırlık ---------------------------------------------------------------------------

it('motor modelinde GTİP 12 haneye çevrilir; geçersiz GTİP reddedilir (servis ve veritabanı)', function () {
    expect($this->f->model->fresh())->gtip_code->toBe('850152200000')->net_weight_kg->toBe('8.500');

    expect(fn () => app(MotorModelService::class)->update($this->f->model->fresh(), ['gtip_code' => '8501.52'], $this->f->model->fresh()->lock_version))
        ->toThrow(BusinessRuleException::class, 'GTİP 12 haneli');
    expect(fn () => DB::transaction(fn () => DB::table('motor_models')->where('id', $this->f->model->id)->update(['gtip_code' => '85015'])))
        ->toThrow(QueryException::class, 'motor_models_gtip_check');
});

// --- Doğrudan ihracat (11/1-a) -------------------------------------------------------------------------------

it('ihracat faturası: KDV %0, GTİP modelden, net ağırlık hesaplanır; eksik bilgiyle kesilmez', function () {
    $units = exportMotors(2, $this->foreign);
    $invoice = exportDraft($this->foreign, ExportType::Export, ['currency' => 'EUR', 'exchange_rate' => '48,5']);

    $line = $this->service->addMotorLine($invoice, $units, '120', 0, 20);
    expect($line)->vat_rate->toBe('0.00')->vat_amount->toBe('0.00')->gtip_code->toBe('850152200000');

    expect(fn () => $this->service->issue($invoice->fresh(), $this->admin))
        ->toThrow(BusinessRuleException::class, 'eksik bilgi: teslim şekli, gönderim şekli, kap sayısı, kap cinsi, brüt ağırlık');

    $invoice = $this->service->updateDraft($invoice->fresh(), shippingDetails(), $invoice->fresh()->lock_version);
    $issued = $this->service->issue($invoice->fresh(), $this->admin);

    expect($issued)
        ->vat_total->toBe('0.00')->grand_total->toBe('240.00')->net_weight_kg->toBe('17.000')
        ->incoterm->toBe(Incoterm::FOB)->transport_mode->toBe(TransportMode::Sea)->package_type->toBe(PackageType::Pallet)
        ->and($issued->export_type->exemptionCode())->toBe('301')
        ->and(AccountTransaction::query()->where('reference_id', $issued->id)->where('type', AccountTransactionType::Invoice->value)->value('amount'))->toBe('240.00');
});

it('ihracat yalnızca yurt dışındaki cariye ve yalnızca satış faturasında', function () {
    expect(fn () => exportDraft($this->exporter, ExportType::Export))->toThrow(BusinessRuleException::class, 'yurt dışındaki cariye');

    $supplier = Customer::factory()->create(['is_customer' => false, 'is_supplier' => true]);
    expect(fn () => $this->service->createDraft(['type' => InvoiceType::PurchaseInvoice, 'customer_id' => $supplier->id, 'export_type' => 'export']))
        ->toThrow(BusinessRuleException::class, 'yalnızca satış faturasında');
});

it('taslak ihracata çevrilince satırların KDV si sıfırlanır; veritabanı KDV li ihracat faturasını reddeder', function () {
    $invoice = $this->service->createDraft(['type' => InvoiceType::SalesInvoice, 'customer_id' => $this->foreign->id, 'exchange_rate' => '48,5']);
    $this->service->addLine($invoice, ['description' => 'Yedek ürün', 'quantity' => 1, 'unit_price' => '100', 'vat_rate' => 20]);
    expect($invoice->fresh()->vat_total)->toBe('20.00');

    $invoice = $this->service->updateDraft($invoice->fresh(), ['export_type' => 'export'], $invoice->fresh()->lock_version);

    expect($invoice->fresh())->vat_total->toBe('0.00')->grand_total->toBe('100.00')
        ->and($invoice->lines()->sole()->vat_rate)->toBe('0.00');

    expect(fn () => DB::transaction(fn () => DB::table('invoices')->where('id', $invoice->id)->update(['vat_total' => 5])))
        ->toThrow(QueryException::class, 'invoices_export_vat_check');
});

it('GTİP i olmayan motor satırıyla ihracat faturası kesilmez; modele sonradan girilen GTİP kesimde kullanılır', function () {
    app(MotorModelService::class)->update($this->f->model->fresh(), ['gtip_code' => null], $this->f->model->fresh()->lock_version);
    $invoice = exportDraft($this->foreign, ExportType::Export, shippingDetails());
    $this->service->addMotorLine($invoice, exportMotors(1, $this->foreign), '100');

    expect(fn () => $this->service->issue($invoice->fresh(), $this->admin))->toThrow(BusinessRuleException::class, 'GTİP yok');

    app(MotorModelService::class)->update($this->f->model->fresh(), ['gtip_code' => '850152200000'], $this->f->model->fresh()->lock_version);
    $issued = $this->service->issue($invoice->fresh(), $this->admin);

    expect($issued->lines()->sole()->gtip_code)->toBe('850152200000');
});

// --- İhraç kayıtlı satış (11/1-c) ----------------------------------------------------------------------------

it('ihraç kayıtlı satış: KDV hesaplanır ve tecil edilir, cariye KDV hariç tutar yazılır; son gün 3 ay sonra', function () {
    $invoice = exportDraft($this->exporter, ExportType::RegisteredExport, ['invoice_date' => '2026-07-10']);
    $this->service->addMotorLine($invoice, exportMotors(2, $this->exporter), '1000');
    $issued = $this->service->issue($invoice->fresh(), $this->admin);

    expect($issued)->vat_total->toBe('400.00')->deferred_vat_total->toBe('400.00')->grand_total->toBe('2000.00')
        ->and($issued->export_type->exemptionCode())->toBe('701')
        ->and($issued->exportDeadline()->toDateString())->toBe('2026-10-10')
        ->and(AccountTransaction::query()->where('reference_id', $issued->id)->value('amount'))->toBe('2000.00')
        ->and(Invoice::query()->overdueRegisteredExport()->pluck('id')->all())->toBe([$issued->id]);

    $this->service->recordCustomsDeclaration($issued, '26341300EX000123', '2026-09-30', $this->admin);

    expect(Invoice::query()->overdueRegisteredExport()->count())->toBe(0)
        ->and(Invoice::query()->awaitingCustomsDeclaration()->count())->toBe(0);
});

it('ihraç kayıtlı satış yalnızca vergi numarası olan Türkiye deki cariye', function () {
    expect(fn () => exportDraft($this->foreign, ExportType::RegisteredExport))->toThrow(BusinessRuleException::class, 'ihracatçı cariye');

    $noTax = Customer::factory()->create(['tax_number' => null, 'tax_office' => null]);
    expect(fn () => exportDraft($noTax, ExportType::RegisteredExport))->toThrow(BusinessRuleException::class, 'ihracatçı cariye');
});

it('satış iadesi orijinal ihracat faturasının türünü alır', function () {
    $invoice = exportDraft($this->exporter, ExportType::RegisteredExport);
    $this->service->addMotorLine($invoice, exportMotors(1, $this->exporter), '1000');
    $original = $this->service->issue($invoice->fresh(), $this->admin);

    $return = $this->service->createDraft([
        'type' => InvoiceType::SalesReturn, 'customer_id' => $this->exporter->id, 'counterparty_number' => 'IADE-1', 'original_invoice_id' => $original->id,
    ]);
    $this->service->addLine($return, ['description' => 'İade', 'quantity' => 1, 'unit_price' => '1000', 'vat_rate' => 20]);

    expect($return->fresh())->export_type->toBe(ExportType::RegisteredExport)->deferred_vat_total->toBe('200.00')->grand_total->toBe('1000.00');
    expect(fn () => $this->service->createDraft(['type' => InvoiceType::SalesReturn, 'customer_id' => $this->exporter->id, 'export_type' => 'export']))
        ->toThrow(BusinessRuleException::class, 'orijinal faturadan gelir');
});

// --- Gümrük beyannamesi ---------------------------------------------------------------------------------------

it('gümrük beyannamesi kesilmiş ihracat faturasına sonradan girilir, denetim izine yazılır; diğer alanlar kilitli kalır', function () {
    $invoice = exportDraft($this->foreign, ExportType::Export, shippingDetails());
    $this->service->addMotorLine($invoice, exportMotors(1, $this->foreign), '100');

    expect(fn () => $this->service->recordCustomsDeclaration($invoice->fresh(), 'X1', today(), $this->admin))
        ->toThrow(BusinessRuleException::class, 'kesilmiş ihracat');

    $issued = $this->service->issue($invoice->fresh(), $this->admin);

    expect(fn () => $this->service->recordCustomsDeclaration($issued, 'X1', today()->addDay(), $this->admin))
        ->toThrow(BusinessRuleException::class, 'ileri bir tarih');

    $saved = $this->service->recordCustomsDeclaration($issued, ' 26341300ex000777 ', today(), $this->admin);
    expect($saved)->customs_declaration_number->toBe('26341300EX000777')
        ->and(Activity::query()->where('subject_type', 'invoice')->where('subject_id', $issued->id)->latest('id')->first()->attribute_changes['attributes'] ?? [])
        ->toHaveKey('customs_declaration_number');

    expect(fn () => DB::transaction(fn () => DB::table('invoices')->where('id', $issued->id)->update(['incoterm' => 'EXW'])))
        ->toThrow(QueryException::class, 'Kesilmiş fatura değiştirilemez');
});

it('yurt içi faturaya gümrük beyannamesi girilmez (servis ve veritabanı)', function () {
    $domestic = $this->service->createDraft(['type' => InvoiceType::SalesInvoice, 'customer_id' => $this->exporter->id]);
    $this->service->addLine($domestic, ['description' => 'Hizmet', 'quantity' => 1, 'unit_price' => '10']);
    $issued = $this->service->issue($domestic->fresh(), $this->admin);

    expect(fn () => $this->service->recordCustomsDeclaration($issued, 'X1', today(), $this->admin))->toThrow(BusinessRuleException::class);
    expect(fn () => DB::transaction(fn () => DB::table('invoices')->where('id', $issued->id)->update(['customs_declaration_number' => 'X', 'customs_declaration_date' => today()])))
        ->toThrow(QueryException::class, 'invoices_customs_export_only');
});

// --- Ekranlar --------------------------------------------------------------------------------------------------

it('fatura formunda ihracat bilgileri girilir; detayda beyanname butonu ve listede filtre çalışır', function () {
    Livewire::test(CreateInvoice::class)
        ->fillForm([
            'type' => InvoiceType::SalesInvoice->value, 'customer_id' => $this->foreign->id, 'invoice_date' => today()->toDateString(),
            'currency' => 'EUR', 'exchange_rate' => '48,5', 'export_type' => 'export',
        ])
        ->assertFormFieldVisible('incoterm')
        ->fillForm(['incoterm' => 'CIF', 'transport_mode' => 'road', 'package_count' => 1, 'package_type' => 'crate', 'gross_weight_kg' => '25'])
        ->call('create')
        ->assertHasNoFormErrors();

    $invoice = Invoice::query()->latest('id')->firstOrFail();
    expect($invoice)->export_type->toBe(ExportType::Export)->incoterm->toBe(Incoterm::CIF)->gross_weight_kg->toBe('25.000');

    $this->service->addMotorLine($invoice, exportMotors(1, $this->foreign), '90');

    Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])
        ->assertSee('İhracat (KDV 11/1-a)')
        ->assertActionHidden('customsDeclaration')
        ->callAction('issue')
        ->assertActionVisible('customsDeclaration')
        ->assertSee('Bekleniyor');

    Livewire::test(ListInvoices::class)
        ->filterTable('customs', 'awaiting')
        ->assertCanSeeTableRecords([$invoice]);

    Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])
        ->callAction('customsDeclaration', ['number' => '26341300EX000999', 'date' => today()->toDateString()])
        ->assertHasNoActionErrors()
        ->assertSee('26341300EX000999');

    Livewire::test(ListInvoices::class)
        ->filterTable('customs', 'awaiting')
        ->assertCanNotSeeTableRecords([$invoice]);
});
