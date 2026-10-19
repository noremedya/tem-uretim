<?php

use App\Enums\WorkOrderStatus;
use App\Filament\Resources\ProductionBatches\Pages\CreateProductionBatch;
use App\Filament\Resources\ProductionBatches\ProductionBatchResource;
use App\Filament\Resources\WorkOrders\Pages\CreateWorkOrder;
use App\Filament\Resources\WorkOrders\Pages\EditWorkOrder;
use App\Filament\Resources\WorkOrders\Pages\ListWorkOrders;
use App\Filament\Resources\WorkOrders\Pages\ViewWorkOrder;
use App\Filament\Resources\WorkOrders\RelationManagers\MaterialsRelationManager;
use App\Filament\Resources\WorkOrders\RelationManagers\StatusHistoriesRelationManager;
use App\Filament\Resources\WorkOrders\WorkOrderResource;
use App\Models\Order;
use App\Models\ProductionBatch;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\WorkOrderService;
use Livewire\Livewire;
use Tests\Support\ProductionFixtures;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->operator = User::factory()->operator()->create(['name' => 'Ali Operatör']);
    $this->otherOperator = User::factory()->operator()->create();
    $this->warehouse = User::factory()->warehouse()->create();
    $this->f = ProductionFixtures::create();
});

it('yönetici formdan iş emri oluşturur ve detay sayfasına yönlenir', function () {
    $this->actingAs($this->admin);
    $order = Order::factory()->open()->withItem(5, $this->f->model)->create();

    $component = Livewire::test(CreateWorkOrder::class)
        ->fillForm([
            'product_part_id' => $this->f->model->finished_part_id,
            'quantity' => '3',
            'order_item_id' => $order->items()->sole()->id,
            'assigned_user_id' => $this->operator->id,
            'planned_end' => today()->addDays(3)->toDateString(),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $wo = WorkOrder::sole();
    $component->assertRedirect(WorkOrderResource::getUrl('view', ['record' => $wo]));

    expect($wo)->quantity->toBe('3.000')->assigned_user_id->toBe($this->operator->id)->order_item_id->toBe($order->items()->sole()->id)
        ->and($wo->materials()->count())->toBe(3);
});

it('planlanan bitiş zorunludur; iş kuralı ihlali bildirim olarak gösterilir', function () {
    $this->actingAs($this->admin);

    Livewire::test(CreateWorkOrder::class)
        ->fillForm(['product_part_id' => $this->f->model->finished_part_id, 'quantity' => '1'])
        ->call('create')
        ->assertHasFormErrors(['planned_end' => 'required']);

    Livewire::test(CreateWorkOrder::class)
        ->fillForm(['product_part_id' => $this->f->model->finished_part_id, 'quantity' => '1,5', 'planned_end' => today()->toDateString()])
        ->call('create')
        ->assertNotified('İşlem yapılamadı');

    expect(WorkOrder::count())->toBe(0);
});

it('ürün aramasında yalnızca reçeteli yarı mamul ve mamuller çıkar', function () {
    $this->actingAs($this->admin);

    $results = Livewire::test(CreateWorkOrder::class)->instance()
        ->form->getComponent('product_part_id')->getSearchResults('');

    expect(array_keys($results))->toEqualCanonicalizing([$this->f->model->finished_part_id, $this->f->stator->id]);
});

it('sipariş kalemi seçenekleri yalnızca mamulde ve açık siparişlerin aynı modeldeki kalemleri', function () {
    $this->actingAs($this->admin);
    $open = Order::factory()->open()->withItem(5, $this->f->model)->create();
    Order::factory()->cancelled()->withItem(5, $this->f->model)->create();
    Order::factory()->open()->withItem(5)->create();

    $component = Livewire::test(CreateWorkOrder::class)
        ->fillForm(['product_part_id' => $this->f->stator->id])
        ->assertFormFieldHidden('order_item_id')
        ->fillForm(['product_part_id' => $this->f->model->finished_part_id])
        ->assertFormFieldVisible('order_item_id');

    $options = $component->instance()->form->getComponent('order_item_id')->getOptions();
    expect(array_keys($options))->toBe([$open->items()->sole()->id])
        ->and(reset($options))->toContain($open->order_number)->toContain('kalan 5');
});

it('operatör tüm iş emirlerini görür; yalnızca kendine atananı başlatabilir', function () {
    $mine = $this->f->motorWorkOrder($this->admin, 1, ['assigned_user_id' => $this->operator->id]);
    $other = $this->f->motorWorkOrder($this->admin, 1, ['assigned_user_id' => $this->otherOperator->id]);
    $this->actingAs($this->operator);

    Livewire::test(ListWorkOrders::class)
        ->assertCanSeeTableRecords([$mine, $other])
        ->assertActionHidden('create');

    $this->get(WorkOrderResource::getUrl('view', ['record' => $mine]))->assertOk();
    $this->get(WorkOrderResource::getUrl('view', ['record' => $other]))->assertOk();
    $this->get(WorkOrderResource::getUrl('edit', ['record' => $mine]))->assertForbidden();

    Livewire::test(ViewWorkOrder::class, ['record' => $other->getRouteKey()])->assertActionHidden('start');
    Livewire::test(ViewWorkOrder::class, ['record' => $mine->getRouteKey()])->assertActionVisible('start');
});

it('operatör kendi iş emrini başlatır; yönetici aksiyonlarını görmez', function () {
    $wo = $this->f->motorWorkOrder($this->admin, 1, ['assigned_user_id' => $this->operator->id]);
    $this->actingAs($this->operator);

    Livewire::test(ViewWorkOrder::class, ['record' => $wo->getRouteKey()])
        ->assertActionHidden('cancel')
        ->assertActionHidden('refreshMaterials')
        ->assertActionHidden('edit')
        ->callAction('start')
        ->assertNotified('Üretim başladı')
        ->assertActionHidden('start')
        ->assertActionHidden('revertToPlanned');

    expect($wo->fresh()->status)->toBe(WorkOrderStatus::InProgress);
});

it('depo tüm iş emirlerini salt okunur görür', function () {
    $wo = $this->f->motorWorkOrder($this->admin, 1, ['assigned_user_id' => $this->operator->id]);
    $this->actingAs($this->warehouse);

    Livewire::test(ListWorkOrders::class)->assertCanSeeTableRecords([$wo])->assertActionHidden('create');

    Livewire::test(ViewWorkOrder::class, ['record' => $wo->getRouteKey()])
        ->assertActionHidden('start')
        ->assertActionHidden('cancel')
        ->assertActionHidden('edit');

    $this->get(WorkOrderResource::getUrl('create'))->assertForbidden();
});

it('yönetici açıklamayla iptal eder ve planlamaya geri alır', function () {
    $this->actingAs($this->admin);
    $toCancel = $this->f->motorWorkOrder($this->admin, 1);
    $toRevert = $this->f->motorWorkOrder($this->admin, 1);
    app(WorkOrderService::class)->start($toRevert, $this->admin);

    Livewire::test(ViewWorkOrder::class, ['record' => $toCancel->getRouteKey()])
        ->callAction('cancel', data: ['reason' => ''])
        ->assertHasActionErrors(['reason' => 'required']);

    Livewire::test(ViewWorkOrder::class, ['record' => $toCancel->getRouteKey()])
        ->callAction('cancel', data: ['reason' => 'Müşteri vazgeçti'])
        ->assertNotified('İş emri iptal edildi');

    Livewire::test(ViewWorkOrder::class, ['record' => $toRevert->getRouteKey()])
        ->callAction('revertToPlanned', data: ['reason' => 'Malzeme eksik'])
        ->assertNotified('İş emri planlamaya geri alındı');

    expect($toCancel->fresh())->status->toBe(WorkOrderStatus::Cancelled)->cancellation_reason->toBe('Müşteri vazgeçti')
        ->and($toRevert->fresh()->status)->toBe(WorkOrderStatus::Planned);
});

it('düzenleme formu açılış sürümünü taşır; iki oturumda ikinci kayıt reddedilir', function () {
    $wo = $this->f->motorWorkOrder($this->admin, 2);
    $secondAdmin = User::factory()->admin()->create();

    $this->actingAs($this->admin);
    $a = Livewire::test(EditWorkOrder::class, ['record' => $wo->getRouteKey()]);
    $this->actingAs($secondAdmin);
    $b = Livewire::test(EditWorkOrder::class, ['record' => $wo->getRouteKey()]);

    $this->actingAs($this->admin);
    $a->fillForm(['quantity' => '4'])->call('save')->assertHasNoFormErrors();

    $this->actingAs($secondAdmin);
    $b->fillForm(['notes' => 'B notu'])->call('save')->assertNotified('Kayıt başka biri tarafından değiştirildi');

    expect($wo->fresh())->quantity->toBe('4.000')->notes->toBeNull()
        ->and($wo->materials()->where('part_id', $this->f->bearing->id)->value('required_quantity'))->toBe('8.000');
});

it('malzeme sekmesi eksikleri, geçmiş sekmesi geçişleri gösterir', function () {
    $this->actingAs($this->admin);
    $wo = $this->f->motorWorkOrder($this->admin, 10);
    app(WorkOrderService::class)->start($wo, $this->admin);

    Livewire::test(MaterialsRelationManager::class, ['ownerRecord' => $wo, 'pageClass' => ViewWorkOrder::class])
        ->assertCanSeeTableRecords($wo->materials)
        ->assertSee('RUL-6202')
        ->assertSee('20 '.$this->f->piece->name);

    Livewire::test(StatusHistoriesRelationManager::class, ['ownerRecord' => $wo, 'pageClass' => ViewWorkOrder::class])
        ->assertCanSeeTableRecords($wo->statusHistories)
        ->assertSee('Üretimde');

    $this->get(WorkOrderResource::getUrl('view', ['record' => $wo]))->assertOk()->assertSee('3 malzemede eksik var');
});

it('reçeteyi yenile aksiyonu çalışır', function () {
    $this->actingAs($this->admin);
    $wo = $this->f->motorWorkOrder($this->admin, 1);

    Livewire::test(ViewWorkOrder::class, ['record' => $wo->getRouteKey()])
        ->callAction('refreshMaterials')
        ->assertNotified('Malzeme listesi güncellendi');
});

it('liste: gecikenler filtresi ve sayfalar açılır', function () {
    $this->actingAs($this->admin);
    $late = $this->f->motorWorkOrder($this->admin, 1, ['planned_end' => today()->subDay()]);
    $onTime = $this->f->motorWorkOrder($this->admin, 1);

    Livewire::test(ListWorkOrders::class)
        ->filterTable('overdue')
        ->assertCanSeeTableRecords([$late])
        ->assertCanNotSeeTableRecords([$onTime]);

    $this->get(WorkOrderResource::getUrl('index'))->assertOk();
    $this->get(WorkOrderResource::getUrl('create'))->assertOk();
    $this->get(WorkOrderResource::getUrl('edit', ['record' => $late]))->assertOk();
});

it('üretim partisini yönetici oluşturur; operatör oluşturamaz', function () {
    $this->actingAs($this->admin);

    Livewire::test(CreateProductionBatch::class)
        ->fillForm(['batch_number' => ' PRT-2026-01 ', 'date' => today()->toDateString()])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(ProductionBatch::sole()->batch_number)->toBe('PRT-2026-01');

    // Partiler yalnızca yöneticiye açık.
    foreach ([$this->operator, $this->warehouse] as $user) {
        $this->actingAs($user);
        $this->get(ProductionBatchResource::getUrl('index'))->assertForbidden();
        $this->get(ProductionBatchResource::getUrl('create'))->assertForbidden();
    }
});
