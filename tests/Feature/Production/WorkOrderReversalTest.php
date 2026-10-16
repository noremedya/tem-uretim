<?php

use App\Enums\MotorUnitStatus;
use App\Enums\OrderStatus;
use App\Enums\StockMovementType;
use App\Enums\WorkOrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\WorkOrders\Pages\ViewWorkOrder;
use App\Models\MotorUnit;
use App\Models\Order;
use App\Models\Part;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\WorkOrderStatusHistory;
use App\Services\ProductionService;
use App\Services\StockService;
use App\Services\WorkOrderService;
use Livewire\Livewire;
use Tests\Support\ProductionFixtures;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->operator = User::factory()->operator()->create();
    $this->f = ProductionFixtures::create()->stockUp($this->admin);
    $this->production = app(ProductionService::class);
});

function stockOf(Part $part): string
{
    return (string) $part->stockBalance()->value('quantity');
}

it('tamamlanmış iş emrini geri alır: ters kayıtlar, motorlar iptal, iş emri üretimde', function () {
    $wo = $this->f->startedMotorWorkOrder($this->admin, 10);
    $this->production->complete($wo, $this->admin, [$this->f->varnish->id => '0,3']);

    $this->production->reverse($wo, $this->admin, 'Yanlış modelle tamamlandı');

    expect(stockOf($this->f->stator))->toBe('100.000')
        ->and(stockOf($this->f->bearing))->toBe('1000.000')
        ->and(stockOf($this->f->varnish))->toBe('100.000')
        ->and(stockOf($this->f->model->finishedPart))->toBe('0.000');

    $corrections = StockMovement::where('type', StockMovementType::Correction)->get();
    expect($corrections)->toHaveCount(5) // 3 sarf + 1 fire + 1 üretimden giriş
        ->and($corrections->every(fn ($m) => $m->reference_type === 'work_order' && $m->reference_id === $wo->id && $m->corrected_movement_id !== null))->toBeTrue()
        ->and($corrections->first()->description)->toContain('Yanlış modelle tamamlandı');

    expect(MotorUnit::where('status', MotorUnitStatus::Voided)->count())->toBe(10);

    $wo->refresh();
    expect($wo)->status->toBe(WorkOrderStatus::InProgress)->completed_at->toBeNull()
        ->and($wo->materials()->whereNotNull('actual_scrap')->count())->toBe(0)
        ->and(WorkOrderStatusHistory::where('work_order_id', $wo->id)->latest('id')->first())
        ->from_status->toBe(WorkOrderStatus::Completed)->to_status->toBe(WorkOrderStatus::InProgress)->description->toBe('Yanlış modelle tamamlandı');

    $this->artisan('stock:reconcile')->assertSuccessful();
});

it('geri alınan iş emri tekrar tamamlanınca yeni seri numaraları verilir', function () {
    $wo = $this->f->startedMotorWorkOrder($this->admin, 2);
    $this->production->complete($wo, $this->admin);
    $this->production->reverse($wo, $this->admin, 'Test hatası');
    $this->production->complete($wo->fresh(), $this->admin);

    $prefix = 'TM-71-'.now()->format('ym');
    expect(MotorUnit::where('status', MotorUnitStatus::InStock)->pluck('serial_number')->sort()->values()->all())
        ->toBe(["{$prefix}-00003", "{$prefix}-00004"])
        ->and(stockOf($this->f->model->finishedPart))->toBe('2.000');

    // İkinci geri alma yalnızca ikinci tamamlamanın hareketlerini ters kaydeder.
    $this->production->reverse($wo->fresh(), $this->admin, 'Yine');
    expect(stockOf($this->f->model->finishedPart))->toBe('0.000')
        ->and(MotorUnit::where('status', MotorUnitStatus::Voided)->count())->toBe(4);
    $this->artisan('stock:reconcile')->assertSuccessful();
});

it('sevk edilmiş motor varsa geri alınamaz ve hiçbir şey değişmez', function () {
    $wo = $this->f->startedMotorWorkOrder($this->admin, 2);
    $this->production->complete($wo, $this->admin);
    $this->production->ship([MotorUnit::first()], $this->admin);
    $before = StockMovement::count();

    expect(fn () => $this->production->reverse($wo, $this->admin, 'x'))->toThrow(BusinessRuleException::class, 'Sevk edilmiş motor var');

    expect(StockMovement::count())->toBe($before)
        ->and($wo->fresh()->status)->toBe(WorkOrderStatus::Completed)
        ->and(MotorUnit::where('status', MotorUnitStatus::Voided)->count())->toBe(0);
});

it('üretilen yarı mamul kullanıldıysa geri alınamaz', function () {
    $statorWo = app(WorkOrderService::class)->start($this->f->statorWorkOrder($this->admin, 4), $this->admin);
    $this->production->complete($statorWo, $this->admin);
    // Stokta 104 stator vardı; 104'ünü de tüketen motor üretimi.
    $this->production->complete($this->f->startedMotorWorkOrder($this->admin, 102), $this->admin);

    expect(fn () => $this->production->reverse($statorWo, $this->admin, 'x'))->toThrow(BusinessRuleException::class, 'bu arada kullanılmış');
    expect($statorWo->fresh()->status)->toBe(WorkOrderStatus::Completed);
});

it('yalnızca yönetici, yalnızca tamamlanmış iş emrini, açıklamayla geri alır', function () {
    $wo = $this->f->startedMotorWorkOrder($this->admin, 1, ['assigned_user_id' => $this->operator->id]);

    expect(fn () => $this->production->reverse($wo, $this->admin, 'x'))->toThrow(BusinessRuleException::class);

    $this->production->complete($wo, $this->operator);

    expect(fn () => $this->production->reverse($wo, $this->operator, 'x'))->toThrow(BusinessRuleException::class, 'yalnızca yönetici')
        ->and(fn () => $this->production->reverse($wo, $this->admin, '  '))->toThrow(BusinessRuleException::class, 'açıklaması zorunludur');
});

it('geri alma sipariş durumunu yeniden hesaplar (tamamlandı → kısmi → açık)', function () {
    $order = Order::factory()->open()->withItem(3, $this->f->model)->create();
    $item = $order->items()->sole();
    $first = $this->f->startedMotorWorkOrder($this->admin, 1, ['order_item_id' => $item->id]);
    $second = $this->f->startedMotorWorkOrder($this->admin, 2, ['order_item_id' => $item->id]);
    $this->production->complete($first, $this->admin);
    $this->production->complete($second, $this->admin);
    expect($order->fresh()->status)->toBe(OrderStatus::Completed);

    $this->production->reverse($second, $this->admin, 'x');
    expect($order->fresh()->status)->toBe(OrderStatus::Partial)->and($item->fresh()->producedQuantity())->toBe(1);

    $this->production->reverse($first, $this->admin, 'x');
    expect($order->fresh()->status)->toBe(OrderStatus::Open);
});

it('ters kaydı elle yapılamaz; geri alma aksiyonu yalnızca yöneticiye görünür', function () {
    $wo = $this->f->startedMotorWorkOrder($this->admin, 1, ['assigned_user_id' => $this->operator->id]);
    $this->production->complete($wo, $this->admin);

    $output = StockMovement::where('type', StockMovementType::ProductionOutput)->sole();
    expect(fn () => app(StockService::class)->correct($output, $this->admin, 'x'))
        ->toThrow(BusinessRuleException::class, 'elle ters kaydedilemez');

    $this->actingAs($this->operator);
    Livewire::test(ViewWorkOrder::class, ['record' => $wo->getRouteKey()])->assertActionHidden('reverse');

    $this->actingAs($this->admin);
    Livewire::test(ViewWorkOrder::class, ['record' => $wo->getRouteKey()])
        ->callAction('reverse', data: ['reason' => 'Ekrandan geri alma'])
        ->assertNotified('Üretim geri alındı');

    expect($wo->fresh()->status)->toBe(WorkOrderStatus::InProgress);
});
