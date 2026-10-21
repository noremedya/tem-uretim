<?php

use App\Enums\Currency;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\PartType;
use App\Enums\StockMovementType;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\StaleModelException;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\MotorUnit;
use App\Models\Order;
use App\Models\Part;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ExchangeRateService;
use App\Services\InvoiceService;
use App\Services\PriceService;
use App\Services\ProductionService;
use App\Services\StockService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProductionFixtures;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->service = app(InvoiceService::class);
    $this->customer = Customer::factory()->create(['payment_term_days' => 30]);
    $this->supplier = Customer::factory()->create(['is_customer' => false, 'is_supplier' => true]);
    $this->f = null;
});

/** Üretilip sevk edilmiş $count motor (siparişe bağlı, $customer'ın). */
function shippedMotors(int $count, ?Customer $customer = null): array
{
    $t = test();
    $t->f ??= ProductionFixtures::create()->stockUp($t->admin);
    $order = Order::factory()->open()->withItem($count, $t->f->model)->create(['customer_id' => ($customer ?? $t->customer)->id]);
    $wo = $t->f->startedMotorWorkOrder($t->admin, $count, ['order_item_id' => $order->items()->sole()->id]);
    app(ProductionService::class)->complete($wo, $t->admin);
    $units = MotorUnit::where('work_order_id', $wo->id)->orderBy('id')->get();
    app(ProductionService::class)->ship($units, $t->admin);

    return $units->all();
}

function draft(InvoiceType $type, array $data = []): Invoice
{
    $t = test();

    return app(InvoiceService::class)->createDraft([
        'type' => $type,
        'customer_id' => ($type->requiresCustomer() ? $t->customer : $t->supplier)->id,
        ...$data,
    ]);
}

it('taslak açar: vade carinin vade günüyle, para birimi carinin varsayılanıyla', function () {
    $invoice = draft(InvoiceType::SalesInvoice, ['invoice_date' => '2026-10-17']);

    expect($invoice)->status->toBe(InvoiceStatus::Draft)->currency->toBe(Currency::TRY)->exchange_rate->toBe('1.000000')
        ->and($invoice->due_date->toDateString())->toBe('2026-11-16')
        ->and($invoice->invoice_number)->toBeNull();
});

it('satır tutarları: brüt, iskonto, net, KDV kuruşa yarım yukarı yuvarlanır', function () {
    $invoice = draft(InvoiceType::SalesInvoice);
    $line = $this->service->addLine($invoice, ['description' => 'Nakliye', 'quantity' => '3', 'unit_price' => '33,335', 'discount_rate' => 15, 'vat_rate' => 20]);

    // 3 × 33,335 = 100,005 → 100,01; iskonto %15 = 15,0015 → 15,00; net 85,01; KDV 17,002 → 17,00.
    expect($line)->line_gross->toBe('100.01')->discount_amount->toBe('15.00')->line_net->toBe('85.01')->vat_amount->toBe('17.00')->line_total->toBe('102.01')
        ->and($invoice->fresh())->subtotal->toBe('100.01')->discount_total->toBe('15.00')->vat_total->toBe('17.00')->grand_total->toBe('102.01');
});

it('sevk edilen motorlardan satır ekler; fiyat etkin fiyattan gelir', function () {
    $motors = shippedMotors(3);
    app(PriceService::class)->create(['motor_model_id' => $this->f->model->id, 'currency' => 'TRY', 'unit_price' => '15000', 'valid_from' => today()]);
    $invoice = draft(InvoiceType::SalesInvoice);

    $line = $this->service->addMotorLine($invoice, $motors, discountRate: 20);

    expect($line)->quantity->toBe('3.000')->unit_price->toBe('15000.0000')->line_net->toBe('36000.00')->vat_amount->toBe('7200.00')
        ->and($line->description)->toBe('TM-71 Trifaze 0,37 kW')
        ->and($line->motorUnits()->count())->toBe(3);
});

it('motor kuralları: sevk edilmemiş, başka müşterinin, başka faturada olan motor eklenemez', function () {
    $motors = shippedMotors(2);
    $invoice = draft(InvoiceType::SalesInvoice);
    $this->service->addMotorLine($invoice, [$motors[0]], unitPrice: 100);

    $other = draft(InvoiceType::SalesInvoice);
    expect(fn () => $this->service->addMotorLine($other, [$motors[0]], unitPrice: 100))->toThrow(BusinessRuleException::class, 'başka bir faturada');

    $foreignCustomer = Customer::factory()->create();
    $foreignInvoice = draft(InvoiceType::SalesInvoice, ['customer_id' => $foreignCustomer->id]);
    expect(fn () => $this->service->addMotorLine($foreignInvoice, [$motors[1]], unitPrice: 100))->toThrow(BusinessRuleException::class, 'başka bir müşterinin');

    // Stoktaki (sevk edilmemiş) motor.
    app(ProductionService::class)->complete($this->f->startedMotorWorkOrder($this->admin, 1), $this->admin);
    $inStock = MotorUnit::where('status', 'in_stock')->sole();
    expect(fn () => $this->service->addMotorLine($other, [$inStock], unitPrice: 100))->toThrow(BusinessRuleException::class, 'sevk edilmemiş');
});

it('fiyat yoksa veya para birimi farklıysa birim fiyat istenir', function () {
    $motors = shippedMotors(1);
    app(PriceService::class)->create(['motor_model_id' => $this->f->model->id, 'currency' => 'EUR', 'unit_price' => '300', 'valid_from' => today()]);
    $invoice = draft(InvoiceType::SalesInvoice);

    expect(fn () => $this->service->addMotorLine($invoice, $motors))->toThrow(BusinessRuleException::class, 'TRY cinsinden geçerli fiyat yok');
});

it('satış faturası kesilir: FTR numarası, TL karşılığı; kesilen fatura değiştirilemez', function () {
    $motors = shippedMotors(1);
    $invoice = draft(InvoiceType::SalesInvoice);
    $this->service->addMotorLine($invoice, $motors, unitPrice: '1000');

    $issued = $this->service->issue($invoice, $this->admin);

    expect($issued)->status->toBe(InvoiceStatus::Issued)->invoice_number->toBe('FTR-'.now()->year.'-000001')
        ->grand_total->toBe('1200.00')->grand_total_try->toBe('1200.00')->issued_by_user_id->toBe($this->admin->id)
        ->and(fn () => $this->service->addLine($issued, ['description' => 'x', 'quantity' => 1, 'unit_price' => 1]))->toThrow(BusinessRuleException::class, 'Kesilmiş fatura değiştirilemez')
        ->and(fn () => $this->service->deleteDraft($issued))->toThrow(BusinessRuleException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('invoices')->where('id', $issued->id)->update(['grand_total' => 1])))->toThrow(QueryException::class, 'Kesilmiş fatura değiştirilemez')
        ->and(fn () => DB::transaction(fn () => DB::table('invoice_lines')->where('invoice_id', $issued->id)->delete()))->toThrow(QueryException::class, 'satırları değiştirilemez')
        ->and(fn () => DB::transaction(fn () => DB::table('invoices')->where('id', $issued->id)->delete()))->toThrow(QueryException::class, 'silinemez');
});

it('dövizli fatura: kur fatura tarihinin kuruyla dolar, taslakta düzeltilir, TL karşılığı kesimde sabitlenir', function () {
    app(ExchangeRateService::class)->saveManual('EUR', '2026-10-16', '48', '49,5', $this->admin);
    $invoice = draft(InvoiceType::SalesInvoice, ['currency' => 'EUR', 'invoice_date' => '2026-10-17']);
    expect($invoice->exchange_rate)->toBe('49.500000');

    $this->service->addLine($invoice, ['description' => 'Motor', 'quantity' => 1, 'unit_price' => 100, 'vat_rate' => 0]);
    $this->service->updateDraft($invoice->fresh(), ['exchange_rate' => '49,75'], $invoice->fresh()->lock_version);

    expect($this->service->issue($invoice, $this->admin)->grand_total_try)->toBe('4975.00');

    expect(fn () => draft(InvoiceType::SalesInvoice, ['currency' => 'USD']))->toThrow(BusinessRuleException::class, 'USD için');
});

it('fatura iptal edilince motor tekrar faturalanabilir', function () {
    $motors = shippedMotors(1);
    $first = draft(InvoiceType::SalesInvoice);
    $this->service->addMotorLine($first, $motors, unitPrice: 100);
    $this->service->issue($first, $this->admin);

    expect(fn () => $this->service->cancel($first->fresh(), $this->admin, ''))->toThrow(BusinessRuleException::class, 'açıklaması zorunludur');
    $this->service->cancel($first->fresh(), $this->admin, 'Yanlış fiyat');

    $second = draft(InvoiceType::SalesInvoice);
    $this->service->addMotorLine($second, $motors, unitPrice: 110);
    expect($this->service->issue($second, $this->admin)->invoice_number)->toBe('FTR-'.now()->year.'-000002');
});

it('veritabanı bir motorun iptal edilmemiş iki faturada olmasını engeller', function () {
    $motors = shippedMotors(1);
    $first = draft(InvoiceType::SalesInvoice);
    $this->service->addMotorLine($first, $motors, unitPrice: 100);
    $second = draft(InvoiceType::SalesInvoice);
    $line = $this->service->addLine($second, ['description' => 'x', 'quantity' => 1, 'unit_price' => 1]);

    expect(fn () => DB::transaction(fn () => DB::table('invoice_line_motor_units')->insert(['invoice_line_id' => $line->id, 'motor_unit_id' => $motors[0]->id])))
        ->toThrow(QueryException::class, 'başka bir faturaya zaten bağlı');
});

describe('alış faturası', function () {
    beforeEach(function () {
        $this->kg = Unit::factory()->create(['name' => 'kg-alis', 'allows_decimal' => true]);
        $this->copper = Part::factory()->create(['code' => 'HM-BAKIR-A', 'type' => PartType::RawMaterial, 'unit_id' => $this->kg->id]);
    });

    it('bağlı giriş yoksa kesimde stok girişi oluşur; iptalde ters kaydedilir', function () {
        $invoice = draft(InvoiceType::PurchaseInvoice, ['counterparty_number' => 'ABC2026000000123']);
        $this->service->addLine($invoice, ['part_id' => $this->copper->id, 'quantity' => '120,5', 'unit_price' => '410', 'vat_rate' => 20]);

        $this->service->issue($invoice, $this->admin);

        $movement = StockMovement::where('reference_type', 'invoice')->where('reference_id', $invoice->id)->sole();
        expect($movement)->type->toBe(StockMovementType::StockIn)->quantity->toBe('120.500')
            ->and((string) $this->copper->defaultStockBalance()->value('quantity'))->toBe('120.500')
            ->and($invoice->fresh()->invoice_number)->toBeNull();

        $this->service->cancel($invoice->fresh(), $this->admin, 'Hatalı giriş');
        expect((string) $this->copper->defaultStockBalance()->value('quantity'))->toBe('0.000')
            ->and($movement->correction()->exists())->toBeTrue();
    });

    it('satırda seçilen depoya giriş yapılır; iptalde ters kayıt aynı depoda', function () {
        $production = Warehouse::query()->where('code', 'URT')->sole();
        $invoice = draft(InvoiceType::PurchaseInvoice, ['counterparty_number' => 'DEPO-1']);
        $line = $this->service->addLine($invoice, ['part_id' => $this->copper->id, 'warehouse_id' => $production->id, 'quantity' => '10', 'unit_price' => '1']);

        expect($line->warehouse_id)->toBe($production->id);

        $this->service->issue($invoice, $this->admin);
        $movement = StockMovement::where('reference_type', 'invoice')->where('reference_id', $invoice->id)->sole();

        expect($movement->warehouse_id)->toBe($production->id)
            ->and((string) $this->copper->defaultStockBalance()->value('quantity'))->toBe('0.000');

        $this->service->cancel($invoice->fresh(), $this->admin, 'Yanlış depo');
        expect($movement->correction->warehouse_id)->toBe($production->id);
    });

    it('önceden yapılmış stok girişlerine bağlanır; toplam eşit olmalı; aynı mal iki kez girilmez', function () {
        $stock = app(StockService::class);
        $a = $stock->stockIn($this->copper, '50', $this->admin);
        $b = $stock->stockIn($this->copper, '30', $this->admin);
        $invoice = draft(InvoiceType::PurchaseInvoice, ['counterparty_number' => 'T-1']);
        $line = $this->service->addLine($invoice, ['part_id' => $this->copper->id, 'quantity' => '90', 'unit_price' => 1, 'stock_movement_ids' => [$a->id, $b->id]]);

        expect(fn () => $this->service->issue($invoice, $this->admin))->toThrow(BusinessRuleException::class, 'eşit olmalı');

        $this->service->updateLine($line, ['quantity' => '80']);
        $this->service->issue($invoice, $this->admin);

        expect((string) $this->copper->defaultStockBalance()->value('quantity'))->toBe('80.000')
            ->and($this->service->linkableReceipts($this->copper->id))->toHaveCount(0);

        // İptal önceden var olan girişleri ters kaydetmez, yalnızca bağı serbest bırakır.
        $this->service->cancel($invoice->fresh(), $this->admin, 'Yanlış tedarikçi');
        expect((string) $this->copper->defaultStockBalance()->value('quantity'))->toBe('80.000')
            ->and($this->service->linkableReceipts($this->copper->id))->toHaveCount(2);
    });

    it('kullanılmış malzemenin alış faturası iptal edilemez', function () {
        $invoice = draft(InvoiceType::PurchaseInvoice, ['counterparty_number' => 'T-2']);
        $this->service->addLine($invoice, ['part_id' => $this->copper->id, 'quantity' => '10', 'unit_price' => 1]);
        $this->service->issue($invoice, $this->admin);
        app(StockService::class)->stockOut($this->copper, '5', $this->admin, 'Kullanıldı');

        expect(fn () => $this->service->cancel($invoice->fresh(), $this->admin, 'x'))->toThrow(BusinessRuleException::class, 'kullanılmış');
        expect($invoice->fresh()->status)->toBe(InvoiceStatus::Issued);
    });

    it('karşı taraf numarası zorunlu ve aynı caride tekrar edemez; ürün zorunlu', function () {
        $invoice = draft(InvoiceType::PurchaseInvoice);
        $this->service->addLine($invoice, ['part_id' => $this->copper->id, 'quantity' => 1, 'unit_price' => 1]);

        expect(fn () => $this->service->issue($invoice, $this->admin))->toThrow(BusinessRuleException::class, 'belge numarasını girin');

        draft(InvoiceType::PurchaseInvoice, ['counterparty_number' => 'DUP-1']);
        expect(fn () => draft(InvoiceType::PurchaseInvoice, ['counterparty_number' => 'DUP-1']))->toThrow(BusinessRuleException::class, 'zaten kayıtlı')
            ->and(fn () => $this->service->addLine($invoice, ['description' => 'ürünsız', 'quantity' => 1, 'unit_price' => 1]))->toThrow(BusinessRuleException::class, 'ürün seçilmelidir');
    });
});

it('cari türü belge türüne uymalı', function () {
    expect(fn () => draft(InvoiceType::SalesInvoice, ['customer_id' => $this->supplier->id]))->toThrow(BusinessRuleException::class, 'müşteri işaretli')
        ->and(fn () => draft(InvoiceType::PurchaseInvoice, ['customer_id' => $this->customer->id]))->toThrow(BusinessRuleException::class, 'tedarikçi işaretli');
});

it('iadeler: alış iadesi FTR numarası alır, satış iadesi karşı taraf numarasıyla; orijinal faturaya bağlanır', function () {
    $sale = draft(InvoiceType::SalesInvoice);
    $this->service->addLine($sale, ['description' => 'Hizmet', 'quantity' => 1, 'unit_price' => 100]);
    $this->service->issue($sale, $this->admin);

    $salesReturn = draft(InvoiceType::SalesReturn, ['counterparty_number' => 'IADE-77', 'original_invoice_id' => $sale->id]);
    $this->service->addLine($salesReturn, ['description' => 'Hizmet iadesi', 'quantity' => 1, 'unit_price' => 100]);
    expect($this->service->issue($salesReturn, $this->admin))->invoice_number->toBeNull()->counterparty_number->toBe('IADE-77')->original_invoice_id->toBe($sale->id);

    $purchaseReturn = draft(InvoiceType::PurchaseReturn);
    $this->service->addLine($purchaseReturn, ['description' => 'Hatalı tel iadesi', 'quantity' => 1, 'unit_price' => 50]);
    expect($this->service->issue($purchaseReturn, $this->admin)->invoice_number)->toBe('FTR-'.now()->year.'-000002');

    expect(fn () => draft(InvoiceType::PurchaseReturn, ['counterparty_number' => 'X']))->toThrow(BusinessRuleException::class, 'karşı taraf numarası girilmez')
        ->and(fn () => draft(InvoiceType::SalesReturn, ['original_invoice_id' => $purchaseReturn->id]))->toThrow(BusinessRuleException::class, 'aynı carinin');
});

it('taslak silinir; motor serbest kalır; taslak başlığı optimistic locking ile güncellenir', function () {
    $motors = shippedMotors(1);
    $invoice = draft(InvoiceType::SalesInvoice);
    $this->service->addMotorLine($invoice, $motors, unitPrice: 100);

    expect(fn () => $this->service->updateDraft(Invoice::find($invoice->id), ['notes' => 'x'], 0))->toThrow(StaleModelException::class);

    $this->service->deleteDraft($invoice);
    expect(Invoice::count())->toBe(0)
        ->and($this->service->invoiceableMotors(draft(InvoiceType::SalesInvoice)))->toHaveCount(1);
});
