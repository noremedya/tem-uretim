<?php

use App\Enums\InvoiceType;
use App\Enums\MotorUnitStatus;
use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\StockMovementType;
use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\MotorUnits\Pages\ListMotorUnits;
use App\Filament\Resources\MotorUnits\Pages\ViewMotorUnit;
use App\Filament\Widgets\ProductionOverview;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\MotorUnit;
use App\Models\MotorUnitReturn;
use App\Models\Order;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Reports\ProductionCountReport;
use App\Reports\ReportFilters;
use App\Services\InvoiceService;
use App\Services\LabelService;
use App\Services\MotorModelService;
use App\Services\ProductionService;
use App\Services\StockService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
    $this->production = app(ProductionService::class);
    $this->invoices = app(InvoiceService::class);
    $this->model = app(MotorModelService::class)->create(['code' => 'M1', 'name' => 'Motor bir']);
    $this->main = Warehouse::query()->where('code', 'MML')->sole();
    $this->returnsDepot = Warehouse::query()->where('code', 'IADE')->sole();
    $this->customer = Customer::factory()->create(['payment_term_days' => 0]);
});

function balanceAt(Warehouse $warehouse): string
{
    return (string) (DB::table('stock_balances')->where('part_id', test()->model->finished_part_id)->where('warehouse_id', $warehouse->id)->value('quantity') ?? '0');
}

function openingMotor(string $serial = 'ESKI-1'): MotorUnit
{
    $t = test();

    return $t->production->registerOpeningMotors($t->model, $t->main, [$serial], 0, null, $t->admin)->first();
}

function shippedMotor(string $serial = 'ESKI-1'): MotorUnit
{
    $t = test();
    $motor = openingMotor($serial);
    $t->production->ship([$motor], $t->admin);

    return $motor->fresh();
}

function motorInvoice(InvoiceType $type, array $motors, array $header = [], ?Customer $customer = null, bool $issue = true, ?string $price = '100'): Invoice
{
    $t = test();
    $invoice = $t->invoices->createDraft(['type' => $type, 'customer_id' => ($customer ?? $t->customer)->id, ...($type === InvoiceType::SalesReturn ? ['counterparty_number' => 'R-'.fake()->unique()->numerify('#####')] : []), ...$header]);
    $t->invoices->addMotorLine($invoice, $motors, $price);

    return $issue ? $t->invoices->issue($invoice, $t->admin) : $invoice;
}

it('açılış motoru elle ve üretilen seri numarasıyla stoğa girer', function () {
    $units = $this->production->registerOpeningMotors($this->model, $this->main, ['ESKI-1', ' ESKI-2 ', ''], 2, '2025-03-01', $this->admin, 'Sayım');

    expect($units)->toHaveCount(4)
        ->and($units->pluck('serial_number')->take(2)->all())->toBe(['ESKI-1', 'ESKI-2'])
        ->and($units[2]->serial_number)->toStartWith('M1-')
        ->and($units[0]->isOpening())->toBeTrue()
        ->and($units[0]->status)->toBe(MotorUnitStatus::InStock)
        ->and($units[0]->production_date->toDateString())->toBe('2025-03-01')
        ->and(balanceAt($this->main))->toBe('4.000')
        ->and(StockMovement::query()->where('type', StockMovementType::StockIn->value)->where('reference_type', 'motor_unit')->count())->toBe(4);

    Artisan::call('stock:reconcile');
    expect(Artisan::output())->not->toContain('uyuşmuyor');
});

it('açılış motoru kuralları: tekrar eden, büyük/küçük harf farklı seri, boş liste, ileri tarih, pasif model', function () {
    openingMotor('ESKI-1');
    $open = fn (array $serials, int $generate = 0, ?string $date = null) => $this->production->registerOpeningMotors($this->model, $this->main, $serials, $generate, $date, $this->admin);

    expect(fn () => $open(['eski-1']))->toThrow(BusinessRuleException::class, 'zaten kayıtlı')
        ->and(fn () => $open(['A', 'a']))->toThrow(BusinessRuleException::class, 'birden fazla')
        ->and(fn () => $open([]))->toThrow(BusinessRuleException::class, 'Seri numarası veya')
        ->and(fn () => $open(['A'], 0, today()->addDay()->toDateString()))->toThrow(BusinessRuleException::class, 'ileri bir tarih')
        ->and(fn () => $open([], ProductionService::MAX_OPENING_MOTORS + 1))->toThrow(BusinessRuleException::class, 'en fazla');

    app(MotorModelService::class)->deactivate($this->model->fresh());

    expect(fn () => $open(['Z']))->toThrow(BusinessRuleException::class, 'pasif')
        ->and(MotorUnit::query()->count())->toBe(1);
});

it('biri reddedilirse hiçbiri açılmaz, yetkisiz roller giremez', function () {
    openingMotor('VAR');

    expect(fn () => $this->production->registerOpeningMotors($this->model, $this->main, ['YENI', 'var'], 0, null, $this->admin))->toThrow(BusinessRuleException::class)
        ->and(MotorUnit::query()->pluck('serial_number')->all())->toBe(['VAR']);

    foreach (['warehouse', 'operator'] as $role) {
        expect(fn () => $this->production->registerOpeningMotors($this->model, $this->main, ['X'], 0, null, User::factory()->{$role}()->create()))->toThrow(BusinessRuleException::class, 'yetkiniz yok');
    }

    expect(Role::Accounting->permissions())->not->toContain(Permission::MotorUnitsOpening, Permission::MotorUnitsReturn)
        ->and(Role::Warehouse->permissions())->toContain(Permission::MotorUnitsReturn)
        ->and(Role::Operator->permissions())->not->toContain(Permission::MotorUnitsReturn);
});

it('açılış motoru üretim sayılarına girmez ama etikette ve sevkte normal çalışır', function () {
    $motor = openingMotor();

    $filters = ReportFilters::fromArray(['from' => today()->toDateString(), 'to' => today()->toDateString()]);

    expect(ProductionOverview::counts()['today'])->toBe(0)
        ->and(app(ProductionCountReport::class)->rows($filters))->toBe([]);

    $labels = app(LabelService::class)->motorLabels(MotorUnit::query()->whereKey($motor->id)->with(['motorModel', 'workOrder', 'productionBatch'])->get(), $this->admin);

    expect(json_encode($labels, JSON_UNESCAPED_UNICODE))->toContain('Açılış kaydı');

    $this->production->ship([$motor], $this->admin);

    expect($motor->fresh()->status)->toBe(MotorUnitStatus::Shipped)
        ->and(balanceAt($this->main))->toBe('0.000');
});

it('veritabanı mamule motorsuz girişi ve birden fazla adedi reddeder', function () {
    $motor = openingMotor();
    $base = [
        'part_id' => $this->model->finished_part_id, 'warehouse_id' => $this->main->id, 'type' => 'stock_in', 'balance_before' => 1, 'user_id' => $this->admin->id, 'created_at' => now(),
    ];

    expect(fn () => DB::transaction(fn () => DB::table('stock_movements')->insert([...$base, 'quantity' => 1, 'balance_after' => 2])))->toThrow(QueryException::class, 'Mamul kartında')
        ->and(fn () => DB::transaction(fn () => DB::table('stock_movements')->insert([...$base, 'quantity' => 2, 'balance_after' => 3, 'reference_type' => 'motor_unit', 'reference_id' => $motor->id])))->toThrow(QueryException::class, 'Mamul kartında');
});

it('servis mamule normal stok girişini hâlâ reddeder, motor hareketi elle ters kaydedilemez', function () {
    $motor = openingMotor();
    $part = $this->model->finishedPart()->first();

    expect(fn () => app(StockService::class)->stockIn($part, 1, $this->admin, 'x', warehouse: $this->main))->toThrow(BusinessRuleException::class, 'mamul kartı')
        ->and(fn () => app(StockService::class)->correct(StockMovement::query()->where('reference_id', $motor->id)->where('reference_type', 'motor_unit')->sole(), $this->admin, 'x'))->toThrow(BusinessRuleException::class, 'elle ters kaydedilemez');
});

it('yanlış açılış kaydı iptal edilir, stok düşer, seri no tekrar kullanılamaz', function () {
    $motor = openingMotor();

    expect(fn () => $this->production->cancelOpeningMotor($motor, $this->admin, ' '))->toThrow(BusinessRuleException::class, 'açıklaması');

    $this->production->cancelOpeningMotor($motor, $this->admin, 'Yanlış seri');

    expect($motor->fresh()->status)->toBe(MotorUnitStatus::Voided)
        ->and(balanceAt($this->main))->toBe('0.000')
        ->and(fn () => openingMotor())->toThrow(BusinessRuleException::class, 'zaten kayıtlı');
});

it('sevk edilmiş veya taşınmış veya üretilmiş motorun açılışı iptal edilemez', function () {
    $shipped = shippedMotor('S-1');

    expect(fn () => $this->production->cancelOpeningMotor($shipped, $this->admin, 'x'))->toThrow(BusinessRuleException::class, 'stoktaki açılış');

    $this->production->receiveReturn($shipped, $this->main, $this->admin, 'İade');

    expect(fn () => $this->production->cancelOpeningMotor($shipped->fresh(), $this->admin, 'x'))->toThrow(BusinessRuleException::class, 'Sevk edilmiş');

    $moved = openingMotor('S-2');
    $this->production->transferMotors([$moved], $this->returnsDepot, $this->admin);

    expect(fn () => $this->production->cancelOpeningMotor($moved->fresh(), $this->admin, 'x'))->toThrow(BusinessRuleException::class, 'aktarılmış');
});

it('iade motoru seçilen depoda stoğa alır, sevk bilgisini saklar, tekrar sevk edilir', function () {
    $motor = shippedMotor();
    $shippedAt = $motor->shipped_at;

    $this->production->receiveReturn($motor, $this->returnsDepot, $this->admin, 'Sargı arızası');
    $motor->refresh();
    $return = MotorUnitReturn::query()->sole();

    expect($motor->status)->toBe(MotorUnitStatus::InStock)
        ->and($motor->warehouse_id)->toBe($this->returnsDepot->id)
        ->and($motor->shipped_at)->toBeNull()
        ->and(balanceAt($this->returnsDepot))->toBe('1.000')
        ->and(balanceAt($this->main))->toBe('0.000')
        ->and($return->description)->toBe('Sargı arızası')
        ->and($return->shipped_at->toDateTimeString())->toBe($shippedAt->toDateTimeString())
        ->and($return->user_id)->toBe($this->admin->id)
        ->and(StockMovement::query()->where('reference_id', $motor->id)->where('reference_type', 'motor_unit')->orderBy('id')->pluck('type')->all())
        ->toBe([StockMovementType::StockIn, StockMovementType::Shipment, StockMovementType::Return]);

    $this->production->ship([$motor], $this->admin);

    expect(balanceAt($this->returnsDepot))->toBe('0.000');

    Artisan::call('stock:reconcile');
    expect(Artisan::output())->not->toContain('uyuşmuyor');
});

it('iade kuralları: stoktaki motor, boş açıklama, pasif depo, yetki', function () {
    $inStock = openingMotor('A-1');
    $shipped = shippedMotor('A-2');
    $passive = Warehouse::factory()->create(['is_active' => false]);

    expect(fn () => $this->production->receiveReturn($inStock, $this->main, $this->admin, 'x'))->toThrow(BusinessRuleException::class, 'yalnızca sevk edilen')
        ->and(fn () => $this->production->receiveReturn($shipped, $this->main, $this->admin, ''))->toThrow(BusinessRuleException::class, 'zorunludur')
        ->and(fn () => $this->production->receiveReturn($shipped, $passive, $this->admin, 'x'))->toThrow(BusinessRuleException::class, 'pasif')
        ->and(fn () => $this->production->receiveReturn($shipped, $this->main, User::factory()->operator()->create(), 'x'))->toThrow(BusinessRuleException::class, 'yetkiniz yok')
        ->and(MotorUnitReturn::query()->count())->toBe(0);

    expect($this->production->receiveReturn($shipped, $this->main, User::factory()->warehouse()->create(), 'x')->status)->toBe(MotorUnitStatus::InStock);
});

it('pasifleştirilen modelin motoru da iade alınır', function () {
    $motor = shippedMotor();
    app(MotorModelService::class)->deactivate($this->model->fresh());

    expect($this->production->receiveReturn($motor, $this->returnsDepot, $this->admin, 'İade')->status)->toBe(MotorUnitStatus::InStock);
});

it('iade kaydı değiştirilemez ve silinemez', function () {
    $this->production->receiveReturn(shippedMotor(), $this->main, $this->admin, 'İade');

    expect(fn () => DB::transaction(fn () => DB::table('motor_unit_returns')->update(['description' => 'x'])))->toThrow(QueryException::class, 'değiştirilemez')
        ->and(fn () => DB::transaction(fn () => DB::table('motor_unit_returns')->delete()))->toThrow(QueryException::class, 'silinemez');
});

it('faturalı motor iade faturası kesilmeden yeniden faturalanamaz, kesilince başka cariye satılır', function () {
    $motor = shippedMotor();
    motorInvoice(InvoiceType::SalesInvoice, [$motor]);
    $this->production->receiveReturn($motor, $this->main, $this->admin, 'İade');
    $this->production->ship([$motor->fresh()], $this->admin);
    $other = Customer::factory()->create();

    $draft = $this->invoices->createDraft(['type' => InvoiceType::SalesInvoice, 'customer_id' => $other->id]);

    expect($this->invoices->invoiceableMotors($draft))->toHaveCount(0)
        ->and(fn () => $this->invoices->addMotorLine($draft, [$motor], '100'))->toThrow(BusinessRuleException::class, 'iade faturasını kesin');

    motorInvoice(InvoiceType::SalesReturn, [$motor], price: null);

    expect($this->invoices->invoiceableMotors($draft->fresh()))->toHaveCount(1);

    $this->invoices->addMotorLine($draft, [$motor], '150');

    expect($this->invoices->issue($draft->fresh(), $this->admin)->status->value)->toBe('issued');
});

it('veritabanı iade faturası kesilmeden ikinci satışı reddeder', function () {
    $motor = shippedMotor();
    motorInvoice(InvoiceType::SalesInvoice, [$motor]);
    $draft = $this->invoices->createDraft(['type' => InvoiceType::SalesInvoice, 'customer_id' => $this->customer->id]);
    $this->invoices->addLine($draft, ['description' => 'x', 'quantity' => 1, 'unit_price' => 1]);
    $line = $draft->lines()->first();

    expect(fn () => DB::transaction(fn () => DB::table('invoice_line_motor_units')->insert(['invoice_line_id' => $line->id, 'motor_unit_id' => $motor->id])))->toThrow(QueryException::class, 'zaten bağlı');
});

it('iade faturası tutarı satış satırından alır, stok ve motoru değiştirmez', function () {
    $motor = shippedMotor();
    $invoice = $this->invoices->createDraft(['type' => InvoiceType::SalesInvoice, 'customer_id' => $this->customer->id]);
    $this->invoices->addMotorLine($invoice, [$motor], '1000', '10', '20');
    $this->invoices->issue($invoice, $this->admin);
    $this->production->receiveReturn($motor, $this->main, $this->admin, 'İade');
    $before = balanceAt($this->main);

    $return = motorInvoice(InvoiceType::SalesReturn, [$motor], price: null);

    expect((string) $return->grand_total)->toBe((string) $invoice->fresh()->grand_total)
        ->and($return->lines()->first())->discount_rate->toBe('10.00')
        ->and($return->lines()->first()->vat_rate)->toBe('20.00')
        ->and(balanceAt($this->main))->toBe($before)
        ->and($motor->fresh()->status)->toBe(MotorUnitStatus::InStock);
});

it('iade faturasına yalnızca stok iadesi alınmış, o carinin faturalı motoru eklenir', function () {
    $noReturn = shippedMotor('A-1');
    motorInvoice(InvoiceType::SalesInvoice, [$noReturn]);

    $unbilled = shippedMotor('A-2');
    $this->production->receiveReturn($unbilled, $this->main, $this->admin, 'İade');

    $otherCustomers = shippedMotor('A-3');
    motorInvoice(InvoiceType::SalesInvoice, [$otherCustomers], customer: $other = Customer::factory()->create());
    $this->production->receiveReturn($otherCustomers, $this->main, $this->admin, 'İade');

    $ok = shippedMotor('A-4');
    $sale = motorInvoice(InvoiceType::SalesInvoice, [$ok]);
    $this->production->receiveReturn($ok, $this->main, $this->admin, 'İade');

    $draft = $this->invoices->createDraft(['type' => InvoiceType::SalesReturn, 'customer_id' => $this->customer->id, 'counterparty_number' => 'R-1']);

    expect($this->invoices->returnableMotors($draft)->pluck('serial_number')->all())->toBe(['A-4'])
        ->and(fn () => $this->invoices->addMotorLine($draft, [$noReturn]))->toThrow(BusinessRuleException::class, 'stok iadesi alınmamış')
        ->and(fn () => $this->invoices->addMotorLine($draft, [$unbilled]))->toThrow(BusinessRuleException::class, 'açık satış faturası yok')
        ->and(fn () => $this->invoices->addMotorLine($draft, [$otherCustomers]))->toThrow(BusinessRuleException::class, 'bu carinin');

    $linked = $this->invoices->createDraft(['type' => InvoiceType::SalesReturn, 'customer_id' => $this->customer->id, 'counterparty_number' => 'R-2', 'original_invoice_id' => $sale->id]);

    expect($this->invoices->returnableMotors($linked)->pluck('serial_number')->all())->toBe(['A-4']);
});

it('sipariş bağı olan motor iade gelince başka cariye satılır, iade gelmeyen satılmaz', function () {
    $motor = shippedMotor();
    $order = Order::factory()->create();
    DB::table('motor_units')->where('id', $motor->id)->update(['order_id' => $order->id]);
    $other = Customer::factory()->create();
    $draft = $this->invoices->createDraft(['type' => InvoiceType::SalesInvoice, 'customer_id' => $other->id]);

    expect(fn () => $this->invoices->addMotorLine($draft, [$motor->fresh()], '100'))->toThrow(BusinessRuleException::class, 'başka bir müşterinin');

    $this->production->receiveReturn($motor->fresh(), $this->main, $this->admin, 'İade');
    $this->production->ship([$motor->fresh()], $this->admin);

    expect($this->invoices->addMotorLine($draft, [$motor->fresh()], '100')->quantity)->toBe('1.000');
});

it('iadesi olan satış ve yeniden faturalanmış iade iptal edilemez, sırayla iptal edilir', function () {
    $motor = shippedMotor();
    $sale = motorInvoice(InvoiceType::SalesInvoice, [$motor]);
    $this->production->receiveReturn($motor, $this->main, $this->admin, 'İade');
    $return = motorInvoice(InvoiceType::SalesReturn, [$motor], price: null);
    $this->production->ship([$motor->fresh()], $this->admin);
    $resale = motorInvoice(InvoiceType::SalesInvoice, [$motor->fresh()]);

    expect(fn () => $this->invoices->cancel($sale, $this->admin, 'x'))->toThrow(BusinessRuleException::class, 'iade faturası var')
        ->and(fn () => $this->invoices->cancel($return, $this->admin, 'x'))->toThrow(BusinessRuleException::class, 'yeniden faturalanmış');

    $this->invoices->cancel($resale, $this->admin, 'x');
    $this->invoices->cancel($return, $this->admin, 'x');

    expect($this->invoices->cancel($sale, $this->admin, 'x')->status->value)->toBe('cancelled');
});

it('iade faturası iptal edilince motor yeniden iade faturasına eklenebilir', function () {
    $motor = shippedMotor();
    motorInvoice(InvoiceType::SalesInvoice, [$motor]);
    $this->production->receiveReturn($motor, $this->main, $this->admin, 'İade');
    $return = motorInvoice(InvoiceType::SalesReturn, [$motor], price: null);
    $draft = $this->invoices->createDraft(['type' => InvoiceType::SalesReturn, 'customer_id' => $this->customer->id, 'counterparty_number' => 'R-9']);

    expect($this->invoices->returnableMotors($draft))->toHaveCount(0);

    $this->invoices->cancel($return, $this->admin, 'Hata');

    expect($this->invoices->returnableMotors($draft))->toHaveCount(1);
});

it('ekranlar: yönetici açılış motoru girer, depo iade alır, yetkisiz görmez', function () {
    Livewire::test(ListMotorUnits::class)
        ->callAction('registerOpening', ['motor_model_id' => $this->model->id, 'warehouse_id' => $this->main->id, 'serials' => "E-1\nE-2", 'generate' => 1])
        ->assertHasNoActionErrors()
        ->assertNotified('3 açılış motoru kaydedildi');

    $motor = MotorUnit::query()->where('serial_number', 'E-1')->sole();

    Livewire::test(ViewMotorUnit::class, ['record' => $motor->getRouteKey()])->assertOk()->assertActionVisible('cancelOpening')->assertActionHidden('receiveReturn');

    $this->production->ship([$motor], $this->admin);
    $this->actingAs(User::factory()->warehouse()->create());

    Livewire::test(ListMotorUnits::class)->assertActionHidden('registerOpening');

    Livewire::test(ViewMotorUnit::class, ['record' => $motor->getRouteKey()])
        ->callAction('receiveReturn', ['warehouse_id' => $this->returnsDepot->id, 'reason' => 'Arıza'])
        ->assertHasNoActionErrors()
        ->assertNotified('İade alındı, motor stokta');

    expect($motor->fresh()->warehouse_id)->toBe($this->returnsDepot->id);

    $this->actingAs(User::factory()->operator()->create());
    $this->production->ship([$motor->fresh()], $this->admin);

    Livewire::test(ViewMotorUnit::class, ['record' => $motor->getRouteKey()])->assertActionHidden('receiveReturn');
});

it('IADE deposu kurulumda açılır', function () {
    expect($this->returnsDepot->is_active)->toBeTrue()
        ->and($this->returnsDepot->default_part_types)->toBe([]);
});
