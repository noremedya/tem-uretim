<?php

use App\Enums\Currency;
use App\Enums\Role;
use App\Filament\Widgets\AssignedWorkOrders;
use App\Filament\Widgets\CriticalStock;
use App\Filament\Widgets\DailyProductionChart;
use App\Filament\Widgets\FinanceOverview;
use App\Filament\Widgets\IdleStock;
use App\Filament\Widgets\OverdueWorkOrders;
use App\Filament\Widgets\ProductionOverview;
use App\Models\Customer;
use App\Models\Part;
use App\Models\User;
use App\Services\CashAccountService;
use App\Services\LedgerService;
use App\Services\ProductionService;
use App\Services\WorkOrderService;
use Carbon\CarbonImmutable;
use Filament\Pages\Dashboard;
use Livewire\Livewire;
use Tests\Support\ProductionFixtures;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->travelTo(CarbonImmutable::parse('2026-10-14 09:00')); // çarşamba
    $this->f = ProductionFixtures::create()->stockUp($this->admin);
});

function completeOn(string $day, int $quantity): void
{
    $t = test();
    $t->travelTo(CarbonImmutable::parse("{$day} 10:00"));
    app(ProductionService::class)->complete($t->f->startedMotorWorkOrder($t->admin, $quantity, ['planned_end' => $day]), $t->admin);
    $t->travelTo(CarbonImmutable::parse('2026-10-14 09:00'));
}

it('üretim kartları: bugün ve bu hafta (pazartesiden) tamamlanan motor, üretimdeki ve geciken iş emri', function () {
    completeOn('2026-10-10', 4); // geçen hafta
    completeOn('2026-10-12', 2); // bu hafta pazartesi
    completeOn('2026-10-14', 3); // bugün
    app(WorkOrderService::class)->start($this->f->motorWorkOrder($this->admin, 1, ['planned_end' => '2026-10-13']), $this->admin); // gecikmiş, üretimde
    $this->f->motorWorkOrder($this->admin, 1, ['planned_end' => '2026-10-20']);

    expect(ProductionOverview::counts())->toBe(['today' => 3, 'week' => 5, 'in_progress' => 1, 'overdue' => 1]);

    $series = DailyProductionChart::series();
    expect($series)->toHaveCount(14)
        ->and(array_key_first($series))->toBe('2026-10-01')
        ->and($series['2026-10-10'])->toBe(4)
        ->and($series['2026-10-14'])->toBe(3)
        ->and($series['2026-10-13'])->toBe(0);
});

it('kritik ve hareketsiz stok listeleri', function () {
    $critical = Part::factory()->critical(10)->withStock(5, $this->admin)->create(['code' => 'KRT-1']);
    $this->travelTo(CarbonImmutable::parse('2026-06-01 09:00'));
    $idle = Part::factory()->withStock(5, $this->admin)->create(['code' => 'ESKI-1']);
    $this->travelTo(CarbonImmutable::parse('2026-10-14 09:00'));
    $this->actingAs($this->admin);

    // Kritik stok listesi depo bazlı bakiye satırlarını gösterir.
    Livewire::test(CriticalStock::class)
        ->assertCanSeeTableRecords([$critical->stockBalances()->sole()])
        ->assertCanNotSeeTableRecords([$idle->stockBalances()->sole()]);
    Livewire::test(IdleStock::class)->assertCanSeeTableRecords([$idle])->assertCanNotSeeTableRecords([$critical]);
});

it('ön muhasebe özeti: vadesi geçmiş alacak ve kasa bakiyesi', function () {
    $cash = app(CashAccountService::class)->create(['kind' => 'cash', 'currency' => 'TRY', 'name' => 'Merkez kasa']);
    app(LedgerService::class)->openCashBalance($cash, '1500', today(), $this->admin);
    $customer = Customer::factory()->create();
    app(LedgerService::class)->openCustomerBalance($customer, Currency::TRY, '750', '2026-09-01', $this->admin);
    $this->actingAs($this->admin);

    Livewire::test(FinanceOverview::class)->assertSee('750,00 TRY')->assertSee('1.500,00 TRY')->assertSee('Merkez kasa');
});

it('her rol yalnızca kendi bölümlerini görür', function (string $role, array $visible) {
    $user = $role === 'accounting' ? User::factory()->withRoles(Role::Accounting)->create() : User::factory()->{$role}()->create();
    $this->actingAs($user);

    $all = [ProductionOverview::class, DailyProductionChart::class, OverdueWorkOrders::class, CriticalStock::class, IdleStock::class, FinanceOverview::class, AssignedWorkOrders::class];

    foreach ($all as $widget) {
        expect($widget::canView())->toBe(in_array($widget, $visible, true), "{$role}: {$widget}");
    }

    $this->get(Dashboard::getUrl())->assertOk();
})->with([
    'yönetici' => ['admin', [ProductionOverview::class, DailyProductionChart::class, OverdueWorkOrders::class, CriticalStock::class, IdleStock::class, FinanceOverview::class]],
    'depo' => ['warehouse', [ProductionOverview::class, OverdueWorkOrders::class, CriticalStock::class, IdleStock::class]],
    'operatör' => ['operator', [AssignedWorkOrders::class]],
    'muhasebe' => ['accounting', [FinanceOverview::class]],
]);

it('yönetici ana sayfası widget\'larla açılır', function () {
    completeOn('2026-10-14', 2);
    $this->actingAs($this->admin);

    $this->get(Dashboard::getUrl())->assertOk()->assertSeeLivewire(ProductionOverview::class)->assertSeeLivewire(DailyProductionChart::class);
    Livewire::test(ProductionOverview::class)->assertSee('Bugün tamamlanan motor')->assertSee('Geciken iş emri');
});
