<?php

use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Pages\CreateOrder;
use App\Filament\Resources\Orders\Pages\EditOrder;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\Customer;
use App\Models\MotorModel;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderService;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Repeater;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
    Repeater::fake();
});

it('formdan kalemleriyle sipariş oluşturulur ve sipariş sayfasına gidilir', function () {
    $this->travelTo('2026-09-29 10:00');
    $customer = Customer::factory()->create();
    [$a, $b] = MotorModel::factory()->count(2)->create();

    Livewire::test(CreateOrder::class)
        ->fillForm([
            'customer_id' => $customer->id,
            'customer_reference' => 'PO-2026-15',
            'order_date' => '2026-09-29',
            'due_date' => '2026-10-15',
            'items' => [
                ['motor_model_id' => $a->id, 'quantity' => '10'],
                ['motor_model_id' => $b->id, 'quantity' => '4'],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect(OrderResource::getUrl('view', ['record' => Order::sole()]));

    $order = Order::sole();
    expect($order)
        ->order_number->toBe('SIP-2026-00001')
        ->customer_reference->toBe('PO-2026-15')
        ->and($order->items()->pluck('quantity', 'motor_model_id')->all())->toEqualCanonicalizing([$a->id => 10, $b->id => 4]);
});

it('form doğrulamaları: kalem, miktar, aynı model, termin', function (Closure $items, array $dates, array $errors) {
    $model = MotorModel::factory()->create();

    Livewire::test(CreateOrder::class)
        ->fillForm(['customer_id' => Customer::factory()->create()->id, ...$dates, 'items' => $items($model)])
        ->call('create')
        ->assertHasFormErrors($errors);

    expect(Order::count())->toBe(0);
})->with([
    'kalemsiz' => [fn () => [], ['order_date' => '2026-09-29', 'due_date' => '2026-10-01'], ['items']],
    'sıfır miktar' => [fn ($m) => [['motor_model_id' => $m->id, 'quantity' => '0']], ['order_date' => '2026-09-29', 'due_date' => '2026-10-01'], ['items.0.quantity']],
    'küsuratlı miktar' => [fn ($m) => [['motor_model_id' => $m->id, 'quantity' => '1,5']], ['order_date' => '2026-09-29', 'due_date' => '2026-10-01'], ['items.0.quantity']],
    'aynı model' => [fn ($m) => [['motor_model_id' => $m->id, 'quantity' => '1'], ['motor_model_id' => $m->id, 'quantity' => '2']], ['order_date' => '2026-09-29', 'due_date' => '2026-10-01'], ['items.0.motor_model_id']],
    'termin önce' => [fn ($m) => [['motor_model_id' => $m->id, 'quantity' => '1']], ['order_date' => '2026-09-29', 'due_date' => '2026-09-28'], ['due_date']],
    'termin yok' => [fn ($m) => [['motor_model_id' => $m->id, 'quantity' => '1']], ['order_date' => '2026-09-29', 'due_date' => null], ['due_date']],
]);

it('pasif müşteri ve pasif motor modeli yeni siparişte seçilemez', function () {
    $inactiveCustomer = Customer::factory()->inactive()->create();
    $inactiveModel = MotorModel::factory()->inactive()->create();

    Livewire::test(CreateOrder::class)
        ->fillForm([
            'customer_id' => $inactiveCustomer->id, 'order_date' => '2026-09-29', 'due_date' => '2026-10-01',
            'items' => [['motor_model_id' => $inactiveModel->id, 'quantity' => '1']],
        ])
        ->call('create')
        ->assertHasFormErrors(['customer_id', 'items.0.motor_model_id']);
});

it('düzenleme formu mevcut kalemlerle dolar ve kalemleri servis üzerinden günceller', function () {
    $keep = MotorModel::factory()->create();
    $add = MotorModel::factory()->create();
    $order = Order::factory()->withItem(3, $keep)->create();

    Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])
        ->assertSchemaStateSet(['items' => [['motor_model_id' => $keep->id, 'quantity' => 3]]])
        ->fillForm(['items' => [
            ['motor_model_id' => $keep->id, 'quantity' => '7'],
            ['motor_model_id' => $add->id, 'quantity' => '2'],
        ]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($order->items()->pluck('quantity', 'motor_model_id')->all())->toEqualCanonicalizing([$keep->id => 7, $add->id => 2])
        ->and($order->fresh()->lock_version)->toBe(1);
});

it('sipariş düzenlemesi iki ayrı oturumda çakışırsa ikincisi reddedilir (kalem değişikliği dahil)', function () {
    $model = MotorModel::factory()->create();
    $order = Order::factory()->withItem(3, $model)->create();
    $other = User::factory()->admin()->create();

    $sessionA = Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()]);
    $this->actingAs($other);
    $sessionB = Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()]);

    $this->actingAs($this->admin);
    $sessionA->fillForm(['items' => [['motor_model_id' => $model->id, 'quantity' => '5']]])
        ->call('save')->assertHasNoFormErrors();

    $this->actingAs($other);
    $sessionB->fillForm(['notes' => 'B notu', 'items' => [['motor_model_id' => $model->id, 'quantity' => '9']]])
        ->call('save')
        ->assertNotified('Kayıt başka biri tarafından değiştirildi');

    expect($order->fresh())->notes->toBeNull()->lock_version->toBe(1)
        ->and($order->items()->value('quantity'))->toBe(5);
});

it('kısmi siparişte müşteri, sipariş tarihi ve kalemler formda kilitlidir; termin değişebilir', function () {
    $order = Order::factory()->partial()->withItem(3)->create();

    Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])
        ->assertFormFieldDisabled('customer_id')
        ->assertFormFieldDisabled('order_date')
        ->assertFormFieldDisabled('items')
        ->fillForm(['due_date' => $order->due_date->addDays(5)->toDateString()])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($order->fresh()->due_date->toDateString())->toBe($order->due_date->addDays(5)->toDateString())
        ->and($order->items()->value('quantity'))->toBe(3);
});

it('tamamlanan ve iptal edilen sipariş düzenleme sayfası açılmaz', function (string $state) {
    $order = Order::factory()->{$state}()->withItem()->create();

    $this->get(OrderResource::getUrl('edit', ['record' => $order]))->assertForbidden();

    Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
        ->assertActionHidden('edit')
        ->assertActionHidden('cancel');
})->with(['completed', 'cancelled']);

it('sipariş sayfadan açıklamayla iptal edilir; açıklama sayfada görünür', function () {
    $order = Order::factory()->withItem()->create();

    Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
        ->callAction('cancel', data: ['reason' => ''])
        ->assertHasActionErrors(['reason' => 'required']);

    expect($order->fresh()->status)->toBe(OrderStatus::Open);

    Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
        ->callAction('cancel', data: ['reason' => 'Müşteri projeyi erteledi'])
        ->assertHasNoActionErrors()
        ->assertNotified('Sipariş iptal edildi')
        ->assertActionHidden('cancel')
        ->assertActionHidden('edit');

    expect($order->fresh())
        ->status->toBe(OrderStatus::Cancelled)
        ->cancellation_reason->toBe('Müşteri projeyi erteledi');

    $this->get(OrderResource::getUrl('view', ['record' => $order]))
        ->assertOk()
        ->assertSee('İptal edildi')
        ->assertSee('Müşteri projeyi erteledi');
});

it('iptal penceresi açıkken sipariş başka biri tarafından iptal edilirse ikinci iptal uygulanmaz', function () {
    $order = Order::factory()->withItem()->create();
    $page = Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])->mountAction('cancel');

    app(OrderService::class)->cancel(Order::find($order->id), 'Önce iptal edildi');

    $page->setActionData(['reason' => 'İkinci iptal'])
        ->callMountedAction();

    expect($order->fresh()->cancellation_reason)->toBe('Önce iptal edildi');
});

it('liste müşteri sipariş no, sipariş no ve müşteri adıyla aranır; durumla süzülür', function () {
    $a = Order::factory()->withItem()->create(['customer_reference' => 'PO-ALFA-1']);
    $b = Order::factory()->withItem()->create(['customer_reference' => 'PO-BETA-2']);
    $c = Order::factory()->cancelled()->withItem()->create();

    Livewire::test(ListOrders::class)
        ->searchTable('ALFA')
        ->assertCanSeeTableRecords([$a])
        ->assertCanNotSeeTableRecords([$b, $c]);

    Livewire::test(ListOrders::class)
        ->searchTable($b->order_number)
        ->assertCanSeeTableRecords([$b])
        ->assertCanNotSeeTableRecords([$a]);

    Livewire::test(ListOrders::class)
        ->searchTable(Customer::find($c->customer_id)->name)
        ->assertCanSeeTableRecords([$c]);

    Livewire::test(ListOrders::class)
        ->filterTable('status', [OrderStatus::Cancelled->value])
        ->assertCanSeeTableRecords([$c])
        ->assertCanNotSeeTableRecords([$a, $b]);
});

it('termini geçen açık siparişler süzülebilir', function () {
    $this->travelTo('2026-09-29 10:00');
    $late = Order::factory()->withItem()->create(['order_date' => '2026-09-01', 'due_date' => '2026-09-20']);
    $onTime = Order::factory()->withItem()->create(['order_date' => '2026-09-01', 'due_date' => '2026-10-20']);
    $lateButCancelled = Order::factory()->cancelled()->withItem()->create(['order_date' => '2026-09-01', 'due_date' => '2026-09-20']);

    Livewire::test(ListOrders::class)
        ->filterTable('overdue', true)
        ->assertCanSeeTableRecords([$late])
        ->assertCanNotSeeTableRecords([$onTime, $lateButCancelled]);
});

it('sipariş formundan "+" ile yeni müşteri eklenir ve seçilir', function () {
    Livewire::test(CreateOrder::class)
        ->callAction(
            TestAction::make('createOption')->schemaComponent('customer_id', schema: 'form'),
            data: ['name' => 'Yeni Müşteri Ltd.', 'tax_number' => '9876543210'],
        )
        ->assertHasNoFormErrors()
        ->assertSchemaStateSet(['customer_id' => Customer::where('name', 'Yeni Müşteri Ltd.')->sole()->id]);
});

it('operatör ve depo siparişleri görüntüler; oluşturamaz, düzenleyemez, iptal edemez', function (string $role) {
    $user = User::factory()->{$role}()->create();
    $order = Order::factory()->withItem(2)->create();

    $this->actingAs($user);
    $this->get(OrderResource::getUrl('index'))->assertOk()->assertSee($order->order_number);
    $this->get(OrderResource::getUrl('view', ['record' => $order]))->assertOk();
    $this->get(OrderResource::getUrl('create'))->assertForbidden();
    $this->get(OrderResource::getUrl('edit', ['record' => $order]))->assertForbidden();

    Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
        ->assertActionHidden('edit')
        ->assertActionHidden('cancel');

    expect($user->can('delete', $order))->toBeFalse()
        ->and($this->admin->can('delete', $order))->toBeFalse();
})->with(['operator', 'warehouse']);
