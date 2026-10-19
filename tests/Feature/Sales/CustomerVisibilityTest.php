<?php

use App\Enums\Role;
use App\Filament\Pages\QuickScan;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Models\Customer;
use App\Models\MotorUnit;
use App\Models\Order;
use App\Models\User;
use App\Services\ProductionService;
use Livewire\Livewire;
use Tests\Support\ProductionFixtures;

/*
 * Operatör ve depo müşteri bilgisi görmez (CLAUDE.md bölüm 6): sipariş ekranlarında, genel aramada,
 * Hızlı İşlem motor kartında ve iş emri etiketinde müşteri adı yoktur; yalnızca sipariş no.
 */

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->operator = User::factory()->operator()->create();
    $this->customer = Customer::factory()->create(['name' => 'Gizli Müşteri Sanayi A.Ş.']);
    $this->f = ProductionFixtures::create()->stockUp($this->admin);
    $this->order = Order::factory()->open()->withItem(2, $this->f->model)->create(['customer_id' => $this->customer->id]);
    $this->wo = app(ProductionService::class)->complete(
        $this->f->startedMotorWorkOrder($this->admin, 2, ['order_item_id' => $this->order->items()->sole()->id, 'assigned_user_id' => $this->operator->id]),
        $this->admin,
    );
    $this->motor = MotorUnit::query()->where('work_order_id', $this->wo->id)->firstOrFail();
});

it('sipariş ekranlarında müşteri adı yalnızca cari görebilene (yönetici, muhasebe); operatör ve depo siparişleri hiç görmez', function (Role $role, bool $sees) {
    $user = $role === Role::Operator ? $this->operator : User::factory()->withRoles($role)->create();
    $this->actingAs($user);

    if (! $sees) {
        $this->get(OrderResource::getUrl('view', ['record' => $this->order]))->assertForbidden();
        expect(in_array('customer.name', OrderResource::getGloballySearchableAttributes(), true))->toBeFalse();

        return;
    }

    $this->get(OrderResource::getUrl('view', ['record' => $this->order]))->assertOk()->assertSee($this->customer->name);
    Livewire::test(ListOrders::class)->assertCanSeeTableRecords([$this->order])->assertTableColumnVisible('customer.name');
    expect(in_array('customer.name', OrderResource::getGloballySearchableAttributes(), true))->toBeTrue();
})->with([
    'operatör' => [Role::Operator, false],
    'depo' => [Role::Warehouse, false],
    'yönetici' => [Role::Admin, true],
    'muhasebe' => [Role::Accounting, true],
]);

it('Hızlı İşlem motor kartında operatöre müşteri adı gösterilmez, yöneticiye gösterilir', function () {
    $this->actingAs($this->operator);
    Livewire::test(QuickScan::class)->set('code', $this->motor->serial_number)->call('scan')
        ->assertSee($this->order->order_number)
        ->assertDontSee($this->customer->name);

    $this->actingAs($this->admin);
    Livewire::test(QuickScan::class)->set('code', $this->motor->serial_number)->call('scan')
        ->assertSee($this->customer->name);
});

it('iş emri etiketinde operatöre müşteri adı yazılmaz, yöneticiye yazılır', function () {
    $this->actingAs($this->operator)->get(route('labels.work-order', $this->wo))
        ->assertOk()->assertSee($this->order->order_number)->assertDontSee($this->customer->name);

    $this->actingAs($this->admin)->get(route('labels.work-order', $this->wo))
        ->assertOk()->assertSee($this->customer->name);
});
