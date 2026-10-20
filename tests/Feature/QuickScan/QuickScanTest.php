<?php

use App\Enums\MotorUnitStatus;
use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\WorkOrderStatus;
use App\Filament\Pages\QuickScan;
use App\Filament\Widgets\AssignedWorkOrders;
use App\Filament\Widgets\QuickScanShortcut;
use App\Models\MotorUnit;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\ProductionService;
use App\Services\QuickScanService;
use App\Services\StockService;
use Filament\Pages\Dashboard;
use Livewire\Livewire;
use Tests\Support\ProductionFixtures;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->operator = User::factory()->operator()->create();
    $this->warehouse = User::factory()->warehouse()->create();
    $this->f = ProductionFixtures::create()->stockUp($this->admin);
    $this->f->bearing->forceFill(['barcode' => '8690000062021'])->save();
});

it('kod çözümü: barkod, ürün kodu, seri no, iş emri no; büyük/küçük harf ve boşluk önemsiz', function () {
    $service = app(QuickScanService::class);
    app(ProductionService::class)->complete($wo = $this->f->startedMotorWorkOrder($this->admin, 1), $this->admin);
    $motor = MotorUnit::sole();

    expect($service->resolve(' 8690000062021 ', $this->admin))->type->toBe('part')->record->id->toBe($this->f->bearing->id)
        ->and($service->resolve('rul-6202', $this->admin)['record']->id)->toBe($this->f->bearing->id)
        ->and($service->resolve(strtolower($motor->serial_number), $this->admin))->type->toBe('motor')
        ->and($service->resolve(strtolower($wo->work_order_number), $this->admin))->type->toBe('work_order')
        ->and($service->resolve('YOK-123', $this->admin))->type->toBe('not_found')->message->toBe('Kod bulunamadı.');
});

it('operatör tüm iş emirlerini görür; tüm iş emirlerini görmeyen role başkasınınki gösterilmez', function () {
    $other = $this->f->motorWorkOrder($this->admin, 1, ['assigned_user_id' => User::factory()->operator()->create()->id]);
    $mine = $this->f->motorWorkOrder($this->admin, 1, ['assigned_user_id' => $this->operator->id]);
    $service = app(QuickScanService::class);

    expect($service->resolve($other->work_order_number, $this->operator)['type'])->toBe('work_order')
        ->and($service->resolve($mine->work_order_number, $this->operator)['type'])->toBe('work_order')
        ->and($service->resolve($other->work_order_number, $this->warehouse)['type'])->toBe('work_order');

    // Yalnızca kendine atananları görebilen (work_orders.view, view_all yok) kullanıcı.
    $limited = User::factory()->create();
    $limited->givePermissionTo(Permission::WorkOrdersView->value);

    expect($service->resolve($other->work_order_number, $limited))->type->toBe('not_assigned')->record->toBeNull()->message->toBe('Bu iş emri size atanmamış.');
});

it('depo ürün okutur, hızlı giriş yapar; aynı gönderim anahtarıyla ikinci hareket oluşmaz', function () {
    $this->actingAs($this->warehouse);

    $page = Livewire::test(QuickScan::class)
        ->set('code', '8690000062021')
        ->call('scan')
        ->assertDispatched('quick-scan-done', status: 'success')
        ->assertSee('RUL-6202')
        ->assertSet('code', '');

    $key = $page->get('submissionKey');
    $page->callAction('stockIn', data: ['quantity' => '25', 'description' => 'Hızlı giriş'])
        ->assertNotified('Giriş kaydedildi');

    expect((string) $this->f->bearing->stockBalance()->value('quantity'))->toBe('1025.000')
        ->and($page->get('submissionKey'))->not->toBe($key);

    // Aynı anahtarla tekrar: hareket oluşmaz.
    app(StockService::class)->stockIn($this->f->bearing, '25', $this->warehouse, 'Tekrar', $key);
    expect(StockMovement::where('idempotency_key', $key)->count())->toBe(1);

    $page->callAction('stockOut', data: ['quantity' => '5', 'description' => ''])
        ->assertHasActionErrors(['description' => 'required']);
});

it('operatör ürünü görür ama stok işlemi yapamaz; mamul kartında stok butonu yok', function () {
    $this->actingAs($this->operator);

    Livewire::test(QuickScan::class)
        ->set('code', 'RUL-6202')->call('scan')
        ->assertSee('Bakiye')
        ->assertActionHidden('stockIn')
        ->assertActionHidden('stockOut');

    $this->actingAs($this->admin);
    Livewire::test(QuickScan::class)
        ->set('code', $this->f->model->finishedPart->code)->call('scan')
        ->assertSet('resultType', 'part')
        ->assertActionHidden('stockIn');
});

it('operatör kendi iş emrini okutup başlatır ve tamamlar', function () {
    $wo = $this->f->motorWorkOrder($this->admin, 2, ['assigned_user_id' => $this->operator->id]);
    $this->actingAs($this->operator);

    $page = Livewire::test(QuickScan::class)->set('code', $wo->work_order_number)->call('scan')
        ->assertSee($wo->work_order_number)
        ->assertActionHidden('complete')
        ->callAction('start')
        ->assertNotified('Üretim başladı')
        ->assertDispatched('quick-scan-done', status: 'success');

    $page->callAction('complete')->assertNotified('İş emri tamamlandı');

    expect($wo->fresh()->status)->toBe(WorkOrderStatus::Completed)->and(MotorUnit::count())->toBe(2);
});

it('operatör başkasına atanmış iş emrini okutunca bilgiyi görür ama başlatamaz', function () {
    $other = $this->f->motorWorkOrder($this->admin, 1, ['assigned_user_id' => User::factory()->operator()->create()->id]);
    $this->actingAs($this->operator);

    Livewire::test(QuickScan::class)->set('code', $other->work_order_number)->call('scan')
        ->assertDispatched('quick-scan-done', status: 'success')
        ->assertSee($other->work_order_number)
        ->assertActionHidden('start')
        ->assertActionHidden('complete');
});

it('depo stoktaki motoru okutup sevk eder', function () {
    app(ProductionService::class)->complete($this->f->startedMotorWorkOrder($this->admin, 1), $this->admin);
    $motor = MotorUnit::sole();
    $this->actingAs($this->warehouse);

    Livewire::test(QuickScan::class)->set('code', $motor->serial_number)->call('scan')
        ->assertSee($motor->serial_number)
        ->callAction('ship', data: ['note' => 'Hızlı sevk'])
        ->assertNotified('1 motor sevk edildi')
        ->assertActionHidden('ship');

    expect($motor->fresh()->status)->toBe(MotorUnitStatus::Shipped);

    $this->actingAs($this->operator);
    Livewire::test(QuickScan::class)->set('code', $motor->serial_number)->call('scan')->assertActionHidden('ship');
});

it('bulunamayan kod hata sesiyle bildirilir; sayfa tüm rollere açık', function (string $role) {
    $user = $role === 'accounting' ? User::factory()->withRoles(Role::Accounting)->create() : User::factory()->{$role}()->create();
    $this->actingAs($user);

    $this->get(QuickScan::getUrl())->assertOk()->assertSee('Kamera ile okut');

    Livewire::test(QuickScan::class)->set('code', 'BILINMEYEN')->call('scan')
        ->assertDispatched('quick-scan-done', status: 'error')
        ->assertSee('Kod bulunamadı.');
})->with(['admin', 'operator', 'warehouse', 'accounting']);

it('operatörün ana ekranında atanmış iş emirleri ve Hızlı İşlem butonu', function () {
    $mine = $this->f->motorWorkOrder($this->admin, 1, ['assigned_user_id' => $this->operator->id]);
    $other = $this->f->motorWorkOrder($this->admin, 1);
    $this->actingAs($this->operator);

    $this->get(Dashboard::getUrl())->assertOk()->assertSee('Hızlı İşlem');

    Livewire::test(AssignedWorkOrders::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$other]);

    expect(AssignedWorkOrders::canView())->toBeTrue()
        ->and(QuickScanShortcut::canView())->toBeTrue();

    $this->actingAs($this->warehouse);
    expect(AssignedWorkOrders::canView())->toBeFalse();
});

it('Hızlı İşlem sayfasında harici (CDN) kaynak yoktur', function () {
    $this->actingAs($this->admin);
    $html = $this->get(QuickScan::getUrl())->assertOk()->getContent();

    preg_match_all('/(?:src|href)\s*=\s*"(https?:\/\/[^"]+)"/i', $html, $matches);

    expect(collect($matches[1])->reject(fn (string $url) => str_starts_with($url, config('app.url')))->values()->all())->toBe([]);
});
