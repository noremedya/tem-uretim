<?php

use App\Enums\Role;
use App\Filament\Pages\Reports\OperatorProductionPage;
use App\Filament\Pages\Reports\OrderStatusPage;
use App\Filament\Pages\Reports\ProductionCountPage;
use App\Filament\Pages\Reports\StockBalancePage;
use App\Filament\Pages\Reports\StockMovementListPage;
use App\Filament\Pages\Reports\WorkOrderPerformancePage;
use App\Models\Order;
use App\Models\User;
use App\Reports\OperatorProductionReport;
use App\Reports\OrderStatusReport;
use App\Reports\ProductionCountReport;
use App\Reports\ReportFilters;
use App\Reports\ReportPeriod;
use App\Reports\StockBalanceReport;
use App\Reports\StockMovementListReport;
use App\Reports\WorkOrderPerformanceReport;
use App\Services\ProductionService;
use App\Services\StockService;
use App\Services\WorkOrderService;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Tests\Support\ProductionFixtures;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->ali = User::factory()->operator()->create(['name' => 'Ali']);
    $this->veli = User::factory()->operator()->create(['name' => 'Veli']);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 08:00'));
    $this->f = ProductionFixtures::create()->stockUp($this->admin);
});

/** $start'ta başlayıp $end'de tamamlanan iş emri. */
function produce(array $data, string $start, string $end, string|int $quantity = 2, bool $semi = false)
{
    $t = test();
    $t->travelTo(CarbonImmutable::parse($start));
    $wo = $semi ? $t->f->statorWorkOrder($t->admin, $quantity, $data) : $t->f->motorWorkOrder($t->admin, $quantity, $data);
    app(WorkOrderService::class)->start($wo, $t->admin);
    $t->travelTo(CarbonImmutable::parse($end));
    app(ProductionService::class)->complete($wo->fresh(), $t->admin);

    return $wo->fresh();
}

function filters(array $data = []): ReportFilters
{
    return ReportFilters::fromArray(['preset' => 'custom', 'from' => '2026-10-01', 'to' => '2026-10-31', ...$data]);
}

it('hazır dönemler: hafta pazartesi başlar', function () {
    $today = CarbonImmutable::parse('2026-10-15'); // perşembe

    expect(array_map(fn ($d) => $d->toDateString(), ReportPeriod::range('this_week', $today)))->toBe(['2026-10-12', '2026-10-18'])
        ->and(array_map(fn ($d) => $d->toDateString(), ReportPeriod::range('last_week', $today)))->toBe(['2026-10-05', '2026-10-11'])
        ->and(array_map(fn ($d) => $d->toDateString(), ReportPeriod::range('last_month', $today)))->toBe(['2026-09-01', '2026-09-30'])
        ->and(array_map(fn ($d) => $d->toDateString(), ReportPeriod::range('yesterday', $today)))->toBe(['2026-10-14', '2026-10-14']);
});

it('üretim adedi: model × gün/hafta/ay; iptal edilen motor sayılmaz; yarı mamul isteğe bağlı', function () {
    produce(['planned_end' => '2026-10-10'], '2026-10-05 08:00', '2026-10-05 16:00', 2);
    produce(['planned_end' => '2026-10-10'], '2026-10-06 08:00', '2026-10-06 12:00', 3);
    $reversed = produce(['planned_end' => '2026-10-10'], '2026-10-06 13:00', '2026-10-06 15:00', 4);
    app(ProductionService::class)->reverse($reversed, $this->admin, 'Test');
    produce(['planned_end' => '2026-10-10'], '2026-10-07 08:00', '2026-10-07 10:00', 5, semi: true);

    $daily = app(ProductionCountReport::class)->rows(filters());
    expect(collect($daily)->map(fn ($r) => [$r['period'], $r['quantity']])->all())->toBe([['05.10.2026', '2'], ['06.10.2026', '3']]);

    $monthly = app(ProductionCountReport::class)->rows(filters(['grouping' => 'month', 'include_semi_finished' => true]));
    expect(collect($monthly)->map(fn ($r) => [$r['period'], $r['kind'], $r['quantity']])->all())
        ->toBe([['Ekim 2026', 'Motor', '5'], ['Ekim 2026', 'Yarı mamul', '5.000']])
        ->and(app(ProductionCountReport::class)->summary(filters(), $daily)['Toplam motor'])->toBe('5');

    $weekly = app(ProductionCountReport::class)->rows(filters(['grouping' => 'week']));
    expect($weekly[0]['period'])->toBe('41. hafta (05.10–11.10.2026)');
});

it('iş emri performansı: takvim süresi, zamanında oranı; operatör raporu atanan operatöre yazar', function () {
    produce(['assigned_user_id' => $this->ali->id, 'planned_end' => '2026-10-05'], '2026-10-05 08:00', '2026-10-05 14:00');   // 6 saat, zamanında
    produce(['assigned_user_id' => $this->ali->id, 'planned_end' => '2026-10-06'], '2026-10-06 08:00', '2026-10-08 08:00');   // 48 saat, gecikti
    produce(['assigned_user_id' => $this->veli->id, 'planned_end' => '2026-10-09'], '2026-10-09 08:00', '2026-10-09 11:00');  // 3 saat
    produce(['planned_end' => '2026-10-20'], '2026-10-10 08:00', '2026-10-10 09:00');                                          // atanmamış

    $report = app(WorkOrderPerformanceReport::class);
    $rows = $report->rows(filters());

    expect(collect($rows)->pluck('hours')->all())->toBe(['6.0', '48.0', '3.0', '1.0'])
        ->and(collect($rows)->pluck('on_time')->all())->toBe([true, false, true, true])
        ->and($report->summary(filters(), $rows))->toBe([
            'Tamamlanan iş emri' => '4',
            'Ortalama üretim süresi' => '14,5 saat',
            'Zamanında tamamlanma oranı' => '%75,0',
        ])
        ->and(collect($report->rows(filters(['operator_id' => $this->ali->id])))->count())->toBe(2);

    $operators = collect(app(OperatorProductionReport::class)->rows(filters()))->keyBy('operator');
    expect($operators['Ali'])->toMatchArray(['work_orders' => 2, 'motors' => 4, 'avg_hours' => '27.0', 'on_time_rate' => '50.0'])
        ->and($operators['Veli']['on_time_rate'])->toBe('100.0')
        ->and($operators['Atanmamış']['work_orders'])->toBe(1);
});

it('stok raporu: açılış, hareket tiplerine göre giriş/çıkış, dönem sonu', function () {
    // Eylülde: 1000 giriş (stockUp 05.10'da yapıldı; bunun yerine ayrı parça kullan).
    $part = $this->f->copper;
    $stock = app(StockService::class);
    $this->travelTo(CarbonImmutable::parse('2026-11-02 09:00'));
    $stock->stockOut($part, '100', $this->admin, 'Kasım çıkışı');

    $rows = collect(app(StockBalanceReport::class)->rows(filters(['part_id' => $part->id])));
    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toMatchArray(['accounting_code' => '150.01', 'opening' => '0.000', 'stock_in' => '1000.000', 'stock_out' => '0.000', 'closing' => '1000.000']);

    $november = collect(app(StockBalanceReport::class)->rows(filters(['from' => '2026-11-01', 'to' => '2026-11-30', 'part_id' => $part->id])));
    expect($november[0])->toMatchArray(['opening' => '1000.000', 'stock_in' => '0.000', 'stock_out' => '100.000', 'closing' => '900.000']);

    $list = app(StockMovementListReport::class)->rows(filters(['from' => '2026-11-01', 'to' => '2026-11-30']));
    expect($list)->toHaveCount(1)->and($list[0])->toMatchArray(['type' => 'Çıkış', 'quantity' => '-100.000', 'balance_after' => '900.000']);
});

it('stok raporu üretim sarfı, fire ve üretimden girişi ayrı gösterir; muhasebe koduna göre sıralı', function () {
    produce([], '2026-10-05 08:00', '2026-10-05 10:00', 10);

    $rows = collect(app(StockBalanceReport::class)->rows(filters()))->keyBy('code');

    expect($rows['SARF-VERNIK'])->toMatchArray(['production_consumption' => '2.000', 'production_scrap' => '0.100', 'closing' => '97.900'])
        ->and($rows['TM-71'])->toMatchArray(['accounting_code' => '152', 'production_output' => '10.000', 'closing' => '10.000'])
        ->and($rows->keys()->first())->toBe('HM-BAKIR');
});

it('sipariş durumu: üretilen, sevk edilen, kalan ve gecikme', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 08:00'));
    $order = Order::factory()->open()->withItem(5, $this->f->model)->create(['order_date' => '2026-10-02', 'due_date' => '2026-10-08']);
    $wo = produce(['order_item_id' => $order->items()->sole()->id], '2026-10-05 08:00', '2026-10-05 10:00', 3);
    app(ProductionService::class)->ship($wo->motorUnits->take(1), $this->admin);
    $this->travelTo(CarbonImmutable::parse('2026-10-20 08:00'));

    $rows = app(OrderStatusReport::class)->rows(filters());

    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toMatchArray(['ordered' => 5, 'produced' => 3, 'shipped' => 1, 'remaining' => 2, 'overdue' => true, 'status' => 'Kısmi'])
        ->and(app(OrderStatusReport::class)->summary(filters(), $rows)['Gecikmedeki kalem'])->toBe('1');
});

it('yetkiler: depo yalnızca stok raporlarını görür; operatör ve muhasebe hiçbirini', function () {
    $production = [ProductionCountPage::class, WorkOrderPerformancePage::class, OperatorProductionPage::class, OrderStatusPage::class];
    $stock = [StockBalancePage::class, StockMovementListPage::class];

    $this->actingAs(User::factory()->warehouse()->create());
    foreach ($stock as $page) {
        $this->get($page::getUrl())->assertOk();
    }
    foreach ($production as $page) {
        $this->get($page::getUrl())->assertForbidden();
    }

    foreach ([User::factory()->operator()->create(), User::factory()->withRoles(Role::Accounting)->create()] as $user) {
        $this->actingAs($user);
        foreach ([...$production, ...$stock] as $page) {
            $this->get($page::getUrl())->assertForbidden();
        }
    }

    $this->actingAs($this->admin);
    foreach ([...$production, ...$stock] as $page) {
        $this->get($page::getUrl())->assertOk();
    }
});

it('rapor sayfası filtreyle tabloyu ve özeti günceller', function () {
    produce(['assigned_user_id' => $this->ali->id, 'planned_end' => '2026-10-10'], '2026-10-05 08:00', '2026-10-05 16:00', 2);
    $this->actingAs($this->admin);

    Livewire::test(WorkOrderPerformancePage::class)
        ->set('filters.preset', 'custom')
        ->set('filters.from', '2026-10-01')
        ->set('filters.to', '2026-10-31')
        ->assertSee('Tamamlanan iş emri: 1')
        ->assertSee('8,0')
        ->set('filters.operator_id', $this->veli->id)
        ->assertSee('Tamamlanan iş emri: 0');
});
