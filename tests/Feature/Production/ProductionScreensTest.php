<?php

use App\Enums\MotorUnitStatus;
use App\Enums\WorkOrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\MotorUnits\MotorUnitResource;
use App\Filament\Resources\MotorUnits\Pages\ListMotorUnits;
use App\Filament\Resources\MotorUnits\Pages\ViewMotorUnit;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\WorkOrders\Pages\ViewWorkOrder;
use App\Models\MotorUnit;
use App\Models\Order;
use App\Models\User;
use App\Services\ProductionService;
use App\Services\StockService;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Tests\Support\ProductionFixtures;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->operator = User::factory()->operator()->create();
    $this->warehouse = User::factory()->warehouse()->create();
    $this->f = ProductionFixtures::create()->stockUp($this->admin);
});

it('operatör kendi iş emrini detay sayfasından gerçek fireyle tamamlar', function () {
    $wo = $this->f->startedMotorWorkOrder($this->admin, 2, ['assigned_user_id' => $this->operator->id]);
    $this->actingAs($this->operator);

    Livewire::test(ViewWorkOrder::class, ['record' => $wo->getRouteKey()])
        ->mountAction('complete')
        ->assertSchemaStateSet(["scraps.{$this->f->varnish->id}" => '0,02'], 'mountedActionSchema0')
        ->setActionData(['scraps' => [
            $this->f->stator->id => '0',
            $this->f->bearing->id => '0',
            $this->f->varnish->id => '0,05',
        ]])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNotified('İş emri tamamlandı');

    expect($wo->fresh()->status)->toBe(WorkOrderStatus::Completed)
        ->and(MotorUnit::count())->toBe(2)
        ->and($wo->materials()->where('part_id', $this->f->varnish->id)->value('actual_scrap'))->toBe('0.050');
});

it('yetersiz malzemede tamamlama bildirimle reddedilir', function () {
    $wo = $this->f->startedMotorWorkOrder($this->admin, 200);
    $this->actingAs($this->admin);

    Livewire::test(ViewWorkOrder::class, ['record' => $wo->getRouteKey()])
        ->callAction('complete')
        ->assertNotified('İşlem yapılamadı');

    expect($wo->fresh()->status)->toBe(WorkOrderStatus::InProgress)->and(MotorUnit::count())->toBe(0);
});

it('tamamla aksiyonu yalnızca üretimdeki iş emrinde ve yetkiliye görünür', function () {
    $planned = $this->f->motorWorkOrder($this->admin, 1, ['assigned_user_id' => $this->operator->id]);
    $this->actingAs($this->operator);

    Livewire::test(ViewWorkOrder::class, ['record' => $planned->getRouteKey()])->assertActionHidden('complete');

    $this->actingAs($this->warehouse);
    $started = $this->f->startedMotorWorkOrder($this->admin, 1);
    Livewire::test(ViewWorkOrder::class, ['record' => $started->getRouteKey()])->assertActionHidden('complete');
});

describe('üretilen motorlar', function () {
    beforeEach(function () {
        app(ProductionService::class)->complete($this->f->startedMotorWorkOrder($this->admin, 3), $this->admin);
        $this->units = MotorUnit::orderBy('id')->get();
    });

    it('depo listeden toplu sevk eder', function () {
        $this->actingAs($this->warehouse);

        Livewire::test(ListMotorUnits::class)
            ->assertCanSeeTableRecords($this->units)
            ->selectTableRecords($this->units->take(2)->pluck('id')->all())
            ->callAction(TestAction::make('ship')->table()->bulk(), data: ['note' => 'Plaka 34 ABC 12'])
            ->assertNotified('2 motor sevk edildi');

        expect(MotorUnit::where('status', MotorUnitStatus::Shipped)->count())->toBe(2)
            ->and((string) $this->f->model->finishedPart->defaultStockBalance()->value('quantity'))->toBe('1.000');
    });

    it('depo detay sayfasından tek motor sevk eder; sevk edilmişte aksiyon gizlenir', function () {
        $this->actingAs($this->warehouse);
        $unit = $this->units->first();

        Livewire::test(ViewMotorUnit::class, ['record' => $unit->getRouteKey()])
            ->callAction('ship', data: ['note' => null])
            ->assertNotified('1 motor sevk edildi')
            ->assertActionHidden('ship');
    });

    it('operatör motorları görür ve sevk edebilir', function () {
        $this->actingAs($this->operator);

        Livewire::test(ListMotorUnits::class)
            ->assertCanSeeTableRecords($this->units)
            ->assertActionVisible(TestAction::make('ship')->table($this->units->first()));

        $this->get(MotorUnitResource::getUrl('view', ['record' => $this->units->first()]))->assertOk();
    });

    it('seri no ile aranabilir; sayfalar açılır', function () {
        $this->actingAs($this->admin);

        Livewire::test(ListMotorUnits::class)
            ->searchTable($this->units->last()->serial_number)
            ->assertCanSeeTableRecords([$this->units->last()])
            ->assertCanNotSeeTableRecords([$this->units->first()]);

        $this->get(MotorUnitResource::getUrl('index'))->assertOk();
    });
});

it('sipariş sayfası kalem başına üretilen ve kalan adedi gösterir', function () {
    $order = Order::factory()->open()->withItem(5, $this->f->model)->create();
    app(ProductionService::class)->complete(
        $this->f->startedMotorWorkOrder($this->admin, 2, ['order_item_id' => $order->items()->sole()->id]),
        $this->admin,
    );
    $this->actingAs($this->admin);

    $this->get(OrderResource::getUrl('view', ['record' => $order]))
        ->assertOk()
        ->assertSeeInOrder(['Üretilen', 'Kalan', 'TM-71', '5', '2', '3']);
});

it('stok işlemi sayfası mamul kartını reddeder (sevk yalnızca üretilen motorlar ekranından)', function () {
    expect(fn () => app(StockService::class)->stockOut($this->f->model->finishedPart, 1, $this->admin, 'x'))
        ->toThrow(BusinessRuleException::class, 'Mamul stoğu elle değiştirilemez');
});
