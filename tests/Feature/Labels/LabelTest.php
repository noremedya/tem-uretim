<?php

use App\Enums\Role;
use App\Filament\Resources\MotorUnits\Pages\ListMotorUnits;
use App\Filament\Resources\MotorUnits\Pages\ViewMotorUnit;
use App\Filament\Resources\WorkOrders\Pages\ViewWorkOrder;
use App\Models\MotorUnit;
use App\Models\ReportExport;
use App\Models\User;
use App\Services\ProductionService;
use App\Services\QuickScanService;
use App\Services\UserService;
use App\Support\QrCode;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Tests\Support\ProductionFixtures;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->operator = User::factory()->operator()->create();
    $this->otherOperator = User::factory()->operator()->create();
    $this->f = ProductionFixtures::create()->stockUp($this->admin);

    $this->workOrder = app(ProductionService::class)->complete(
        $this->f->startedMotorWorkOrder($this->admin, 3, ['assigned_user_id' => $this->operator->id]),
        $this->admin,
    );
    $this->motors = MotorUnit::query()->where('work_order_id', $this->workOrder->id)->orderBy('serial_number')->get();
});

it('QR kodu yalnızca kodu taşır ve geri okunur; Hızlı İşlem okunan değeri motora çözer', function () {
    $serial = $this->motors->first()->serial_number;

    expect(QrCode::read(QrCode::png($serial)))->toBe($serial)
        ->and(QrCode::svg($serial))->toStartWith('<svg')->not->toContain('<?xml');

    $resolved = app(QuickScanService::class)->resolve(QrCode::read(QrCode::png($serial)), $this->admin);
    expect($resolved['type'])->toBe('motor')
        ->and($resolved['record']->is($this->motors->first()))->toBeTrue();
});

it('seçili motorların etiketleri seri no, model ve QR ile yazdırılır', function () {
    $ids = $this->motors->take(2)->modelKeys();

    $response = $this->actingAs($this->admin)->get(route('labels.motors', ['ids' => implode(',', $ids)]));

    $response->assertOk()
        ->assertSee($this->motors[0]->serial_number)
        ->assertSee($this->motors[1]->serial_number)
        ->assertDontSee($this->motors[2]->serial_number)
        ->assertSee('TM-71')
        ->assertSee('TEM MOTOR')
        ->assertSee('size: 100mm 50mm', false)
        ->assertSee('(2 etiket)');

    expect(substr_count($response->getContent(), '<svg'))->toBe(2);
    // Harici kaynak yok.
    expect($response->getContent())->not->toMatch('#(?:src|href)\s*=\s*["\'](?:https?:)?//(?!localhost)#i');
});

it('iş emrinin tüm motorları ve A4 düzeni (sayfa başına 24 etiket) seçilebilir', function () {
    $this->actingAs($this->admin)
        ->get(route('labels.motors', ['work_order' => $this->workOrder->id, 'layout' => 'a4']))
        ->assertOk()
        ->assertSee('size: 210mm 297mm', false)
        ->assertSee('grid-template-columns: repeat(3, 70mm)', false)
        ->assertSee('(3 etiket)');
});

it('iş emri etiketi istenen kopya sayısıyla yazdırılır', function () {
    $response = $this->actingAs($this->operator)->get(route('labels.work-order', ['workOrder' => $this->workOrder, 'copies' => 2]));

    $response->assertOk()
        ->assertSee($this->workOrder->work_order_number)
        ->assertSee('Operatör: '.$this->operator->name);

    expect(substr_count($response->getContent(), '<svg'))->toBe(2)
        ->and(QrCode::read(QrCode::png($this->workOrder->work_order_number)))->toBe($this->workOrder->work_order_number);
});

it('operatör tüm iş emirlerinin etiketini alır; iş emri göremeyen (muhasebe) alamaz', function () {
    $this->actingAs($this->otherOperator)->get(route('labels.work-order', $this->workOrder))->assertOk();
    $this->actingAs($this->otherOperator)->get(route('labels.motors', ['work_order' => $this->workOrder->id]))->assertOk();

    $accountant = User::factory()->withRoles(Role::Accounting)->create();
    $this->actingAs($accountant)->get(route('labels.work-order', $this->workOrder))->assertNotFound();
});

it('oturum açmamış kullanıcı giriş sayfasına yönlendirilir (rapor indirme dahil)', function () {
    $this->get(route('labels.motors', ['ids' => $this->motors->first()->id]))->assertRedirect(route('filament.app.auth.login'));
    $this->get(route('labels.work-order', $this->workOrder))->assertRedirect(route('filament.app.auth.login'));

    $export = ReportExport::query()->forceCreate([
        'user_id' => $this->admin->id, 'report_key' => 'production-count', 'filters' => [], 'status' => 'ready', 'file_name' => 'x.xlsx',
    ]);
    $this->get(route('report-exports.download', $export))->assertRedirect(route('filament.app.auth.login'));
});

it('pasifleştirilen kullanıcı etiket sayfasında da çıkışa zorlanır', function () {
    $this->actingAs($this->operator);
    app(UserService::class)->deactivate($this->operator, $this->admin);

    $this->get(route('labels.work-order', $this->workOrder))->assertRedirect(route('filament.app.auth.login'));
    $this->assertGuest();
});

it('tek seferde en fazla yapılandırılan sayıda etiket yazdırılır, motor yoksa 404', function () {
    config(['labels.max_labels' => 2]);

    $this->actingAs($this->admin)
        ->get(route('labels.motors', ['ids' => implode(',', $this->motors->modelKeys())]))
        ->assertStatus(422);

    $this->actingAs($this->admin)->get(route('labels.motors', ['ids' => '999999']))->assertNotFound();
    $this->actingAs($this->admin)->get(route('labels.motors'))->assertNotFound();
});

it('panelde etiket butonları doğru adresi açar', function () {
    $this->actingAs($this->admin);
    $motor = $this->motors->first();

    Livewire::test(ViewMotorUnit::class, ['record' => $motor->getRouteKey()])
        ->assertActionHasUrl('printLabel', route('labels.motors', ['ids' => $motor->id]))
        ->assertActionShouldOpenUrlInNewTab('printLabel');

    Livewire::test(ViewWorkOrder::class, ['record' => $this->workOrder->getRouteKey()])
        ->assertActionHasUrl('printWorkOrderLabel', route('labels.work-order', $this->workOrder))
        ->assertActionVisible('printMotorLabels')
        ->assertActionHasUrl('printMotorLabels', route('labels.motors', ['work_order' => $this->workOrder->id]));

    Livewire::test(ListMotorUnits::class)
        ->selectTableRecords($this->motors->take(2)->modelKeys())
        ->callAction(TestAction::make('printLabels')->table()->bulk())
        ->assertRedirect(route('labels.motors', ['ids' => collect($this->motors->take(2)->modelKeys())->sort()->implode(',')]));
});

it('tamamlanmamış iş emrinde motor etiketleri butonu görünmez', function () {
    $this->actingAs($this->admin);
    $planned = $this->f->motorWorkOrder($this->admin, 2);

    Livewire::test(ViewWorkOrder::class, ['record' => $planned->getRouteKey()])
        ->assertActionVisible('printWorkOrderLabel')
        ->assertActionHidden('printMotorLabels');
});
