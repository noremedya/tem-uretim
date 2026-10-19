<?php

use App\Enums\MotorUnitStatus;
use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Enums\StockMovementType;
use App\Enums\WorkOrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\InsufficientStockException;
use App\Models\MotorModel;
use App\Models\MotorUnit;
use App\Models\Order;
use App\Models\Part;
use App\Models\ProductionBatch;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\WorkOrderStatusHistory;
use App\Services\BomService;
use App\Services\MotorModelService;
use App\Services\OrderService;
use App\Services\ProductionService;
use App\Services\StockService;
use App\Services\WorkOrderService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProductionFixtures;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->operator = User::factory()->operator()->create();
    $this->warehouse = User::factory()->warehouse()->create();
    $this->f = ProductionFixtures::create()->stockUp($this->admin);
    $this->production = app(ProductionService::class);
});

function balance(Part $part): string
{
    return (string) $part->stockBalance()->value('quantity');
}

it('mamul iş emri tamamlanınca sarf, fire, mamul girişi ve seri numaralı motorlar oluşur', function () {
    $batch = ProductionBatch::factory()->create();
    $wo = $this->f->startedMotorWorkOrder($this->admin, 10, ['assigned_user_id' => $this->operator->id, 'production_batch_id' => $batch->id]);

    $this->production->complete($wo, $this->operator);

    // Stator 1/motor, rulman 2/motor (fire yok), vernik 0,2 kg/motor + %5 fire.
    expect(balance($this->f->stator))->toBe('90.000')
        ->and(balance($this->f->bearing))->toBe('980.000')
        ->and(balance($this->f->varnish))->toBe('97.900')
        ->and(balance($this->f->model->finishedPart))->toBe('10.000');

    $movements = StockMovement::where('reference_type', 'work_order')->where('reference_id', $wo->id)->get();
    expect($movements->where('type', StockMovementType::ProductionConsumption)->count())->toBe(3)
        ->and($movements->where('type', StockMovementType::ProductionScrap)->sole())
        ->part_id->toBe($this->f->varnish->id)->quantity->toBe('-0.100')
        ->and($movements->where('type', StockMovementType::ProductionOutput)->sole())
        ->part_id->toBe($this->f->model->finished_part_id)->quantity->toBe('10.000');

    $units = MotorUnit::orderBy('id')->get();
    $prefix = 'TM-71-'.now()->format('y').now()->format('m');
    expect($units)->toHaveCount(10)
        ->and($units->first()->serial_number)->toBe("{$prefix}-00001")
        ->and($units->last()->serial_number)->toBe("{$prefix}-00010")
        ->and($units->every(fn ($u) => $u->status === MotorUnitStatus::InStock
            && $u->work_order_id === $wo->id && $u->production_batch_id === $batch->id
            && $u->production_date->isToday() && $u->order_id === null))->toBeTrue();

    $wo->refresh();
    expect($wo)->status->toBe(WorkOrderStatus::Completed)->completed_at->not->toBeNull()
        ->and($wo->materials()->where('part_id', $this->f->varnish->id)->value('actual_scrap'))->toBe('0.100')
        ->and(WorkOrderStatusHistory::where('work_order_id', $wo->id)->latest('id')->first())
        ->to_status->toBe(WorkOrderStatus::Completed)->user_id->toBe($this->operator->id);
});

it('operatörün girdiği gerçek fire kullanılır (0 dahil)', function () {
    $wo = $this->f->startedMotorWorkOrder($this->admin, 10);

    $this->production->complete($wo, $this->admin, [
        $this->f->varnish->id => '0,35',
        $this->f->bearing->id => '3',
        $this->f->stator->id => '0',
    ]);

    expect(balance($this->f->varnish))->toBe('97.650')
        ->and(balance($this->f->bearing))->toBe('977.000')
        ->and($wo->materials()->where('part_id', $this->f->bearing->id)->value('actual_scrap'))->toBe('3.000');
});

it('fire negatif veya tam sayılı birimde küsuratlı olamaz', function (string $part, string $scrap, string $message) {
    $wo = $this->f->startedMotorWorkOrder($this->admin, 1);

    expect(fn () => $this->production->complete($wo, $this->admin, [$this->f->{$part}->id => $scrap]))->toThrow(BusinessRuleException::class, $message);
    expect($wo->fresh()->status)->toBe(WorkOrderStatus::InProgress);
})->with([
    'negatif' => ['varnish', '-1', 'negatif olamaz'],
    'küsuratlı adet' => ['bearing', '0,5', 'tam sayı olmalıdır'],
]);

it('malzeme yetersizse hiçbir şey değişmez ve eksikler (fire dahil) listelenir', function () {
    $wo = $this->f->startedMotorWorkOrder($this->admin, 10);
    app(StockService::class)->stockOut($this->f->varnish, '98', $this->admin, 'Test');
    $before = StockMovement::count();

    try {
        $this->production->complete($wo, $this->admin);
        $this->fail('Exception beklendi');
    } catch (InsufficientStockException $e) {
        expect($e->shortages)->toHaveCount(1)
            ->and($e->shortages[0])->code->toBe('SARF-VERNIK')
            ->and($e->shortages[0]['required'])->toBe('2.100')
            ->and($e->shortages[0]['missing'])->toBe('0.100');
    }

    expect(StockMovement::count())->toBe($before)
        ->and(MotorUnit::count())->toBe(0)
        ->and($wo->fresh()->status)->toBe(WorkOrderStatus::InProgress)
        ->and($wo->materials()->whereNotNull('actual_scrap')->count())->toBe(0)
        ->and(balance($this->f->model->finishedPart))->toBe('0.000')
        // Seri no sayacı da geri alınır: sonraki tamamlama 00001'den başlar.
        ->and(DB::table('number_sequences')->where('name', 'serial_number')->count())->toBe(0);
});

it('yarı mamul üretilir, sonra motor üretiminde tüketilir; eksik yarı mamul otomatik üretilmez', function () {
    $f = $this->f;
    app(StockService::class)->stockOut($f->stator, '100', $this->admin, 'Test: stator yok');
    $motor = $f->startedMotorWorkOrder($this->admin, 4);

    expect(fn () => $this->production->complete($motor, $this->admin))->toThrow(InsufficientStockException::class, $f->stator->code);

    $statorWo = app(WorkOrderService::class)->start($f->statorWorkOrder($this->admin, 4), $this->admin);
    $this->production->complete($statorWo, $this->admin);

    // 4 stator: 5 kg sac + %4 fire (0,2 kg), 3,2 kg bakır.
    expect(balance($f->stator))->toBe('4.000')
        ->and(balance($f->sheet))->toBe('994.800')
        ->and(balance($f->copper))->toBe('996.800')
        ->and(MotorUnit::count())->toBe(0);

    $this->production->complete($motor->fresh(), $this->admin);

    expect(balance($f->stator))->toBe('0.000')->and(MotorUnit::count())->toBe(4);
});

it('tamamlama yalnızca üretimdeyken ve yetkiliyle yapılır', function () {
    $planned = $this->f->motorWorkOrder($this->admin, 1, ['assigned_user_id' => $this->operator->id]);
    expect(fn () => $this->production->complete($planned, $this->admin))->toThrow(BusinessRuleException::class, 'geçilemez');

    $other = User::factory()->operator()->create();
    $started = $this->f->startedMotorWorkOrder($this->admin, 1, ['assigned_user_id' => $this->operator->id]);

    expect(fn () => $this->production->complete($started, $other))->toThrow(BusinessRuleException::class, 'yetkiniz yok')
        ->and(fn () => $this->production->complete($started, $this->warehouse))->toThrow(BusinessRuleException::class, 'yetkiniz yok');

    $this->production->complete($started, $this->operator);
    expect(fn () => $this->production->complete($started->fresh(), $this->admin))->toThrow(BusinessRuleException::class, 'geçilemez');
});

it('siparişe bağlı iş emrinin motorları siparişe bağlanır; sipariş durumu yeniden hesaplanır', function () {
    $order = Order::factory()->open()->withItem(5, $this->f->model)->create();
    $item = $order->items()->sole();

    $this->production->complete($this->f->startedMotorWorkOrder($this->admin, 2, ['order_item_id' => $item->id]), $this->admin);
    expect($order->fresh()->status)->toBe(OrderStatus::Partial)
        ->and(MotorUnit::where('order_id', $order->id)->count())->toBe(2)
        ->and($item->fresh()->producedQuantity())->toBe(2);

    $this->production->complete($this->f->startedMotorWorkOrder($this->admin, 3, ['order_item_id' => $item->id]), $this->admin);
    expect($order->fresh()->status)->toBe(OrderStatus::Completed);
});

it('iptal edilmiş siparişin motoru siparişe bağlanmaz', function () {
    $order = Order::factory()->open()->withItem(2, $this->f->model)->create();
    $wo = $this->f->startedMotorWorkOrder($this->admin, 2, ['order_item_id' => $order->items()->sole()->id]);
    app(OrderService::class)->cancel($order, 'Müşteri vazgeçti');

    $this->production->complete($wo, $this->admin);

    expect(MotorUnit::whereNull('order_id')->count())->toBe(2)
        ->and($order->fresh()->status)->toBe(OrderStatus::Cancelled);
});

it('seri no sırası model ve ay bazındadır; biçim ayardan değişir', function () {
    $other = MotorModel::factory()->create(['code' => 'MF-56']);
    app(BomService::class)->add($other->finishedPart, $this->f->bearing, 1);

    $this->production->complete($this->f->startedMotorWorkOrder($this->admin, 2), $this->admin);

    config(['numbering.serial_number' => 'S{YIL}-{MODEL_KODU}-{SIRA:4}']);
    $wo = app(WorkOrderService::class)->start(app(WorkOrderService::class)->create([
        'product_part_id' => $other->finished_part_id, 'quantity' => 1, 'planned_end' => today(),
    ], $this->admin), $this->admin);
    $this->production->complete($wo, $this->admin);

    expect(MotorUnit::where('motor_model_id', $other->id)->sole()->serial_number)->toBe('S'.now()->year.'-MF-56-0001')
        ->and(MotorUnit::pluck('serial_number')->unique())->toHaveCount(3);
});

it('motor kaydı silinemez; seri numarası değiştirilemez', function () {
    $this->production->complete($this->f->startedMotorWorkOrder($this->admin, 1), $this->admin);
    $unit = MotorUnit::sole();

    expect(fn () => $unit->delete())->toThrow(BusinessRuleException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('motor_units')->delete()))->toThrow(QueryException::class, 'silinemez')
        ->and(fn () => DB::transaction(fn () => DB::table('motor_units')->update(['serial_number' => 'X'])))->toThrow(QueryException::class, 'değiştirilemez');
});

describe('sevk', function () {
    beforeEach(function () {
        $this->production->complete($this->f->startedMotorWorkOrder($this->admin, 3), $this->admin);
        $this->units = MotorUnit::orderBy('id')->get();
    });

    it('depo stoktaki motorları sevk eder; mamul stoğundan düşülür', function () {
        $this->production->ship($this->units->take(2), $this->warehouse, 'İrsaliye 123');

        expect(MotorUnit::where('status', MotorUnitStatus::Shipped)->count())->toBe(2)
            ->and($this->units->first()->fresh())->shipped_by_user_id->toBe($this->warehouse->id)->notes->toBe('İrsaliye 123')
            ->and(balance($this->f->model->finishedPart))->toBe('1.000')
            ->and(StockMovement::where('type', StockMovementType::Shipment)->where('reference_type', 'motor_unit')->count())->toBe(2);
    });

    it('sevk yetkisi olmayan (muhasebe) sevk edemez; sevk edilmiş motor tekrar sevk edilemez (hiçbiri sevk edilmez)', function () {
        $accountant = User::factory()->withRoles(Role::Accounting)->create();
        expect(fn () => $this->production->ship($this->units, $accountant))->toThrow(BusinessRuleException::class, 'yetkiniz yok');

        $this->production->ship([$this->units->first()], $this->admin);

        expect(fn () => $this->production->ship($this->units, $this->admin))->toThrow(BusinessRuleException::class, $this->units->first()->serial_number)
            ->and(MotorUnit::where('status', MotorUnitStatus::Shipped)->count())->toBe(1);
    });

    it('pasifleştirilen modelin stoktaki motorları sevk edilebilir', function () {
        app(MotorModelService::class)->deactivate($this->f->model->fresh());

        $this->production->ship($this->units, $this->warehouse);

        expect(balance($this->f->model->finishedPart))->toBe('0.000');
    });

    it('stock:reconcile mamul bakiyesi ile stoktaki motor sayısı farkını yakalar', function () {
        $this->artisan('stock:reconcile')->assertSuccessful();

        // Servis dışından motor "sevk edildi" yapılır ama stok düşülmez.
        DB::table('motor_units')->where('id', $this->units->first()->id)
            ->update(['status' => 'shipped', 'shipped_at' => now(), 'shipped_by_user_id' => $this->admin->id]);

        $this->artisan('stock:reconcile')
            ->expectsOutputToContain('1 mamul kartında bakiye stoktaki motor sayısıyla uyuşmuyor')
            ->assertFailed();

        expect($this->admin->notifications()->latest()->first()->data['body'])->toContain('TM-71 Trifaze 0,37 kW: bakiye 3, stoktaki motor 2');
    });
});
