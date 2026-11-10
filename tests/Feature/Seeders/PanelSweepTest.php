<?php

use App\Enums\InvoiceDirection;
use App\Enums\OrderStatus;
use App\Enums\WorkOrderStatus;
use App\Models\BomTemplate;
use App\Models\Brand;
use App\Models\CashAccount;
use App\Models\Customer;
use App\Models\ExchangeRate;
use App\Models\Invoice;
use App\Models\MotorDesign;
use App\Models\MotorModel;
use App\Models\MotorUnit;
use App\Models\Order;
use App\Models\Part;
use App\Models\PartSubtype;
use App\Models\Payment;
use App\Models\Price;
use App\Models\ProductionBatch;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WorkOrder;
use App\Services\WorkOrderService;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

/*
 * Genel gözden geçirme taraması (Aşama 11): demo veriyle paneldeki her GET sayfası her rolle açılır.
 * - Yetki: yanıt CLAUDE.md bölüm 6'daki tabloya uyar (izinli → 200, değilse 403/404; hiçbir sayfa 500 vermez).
 * - N+1: sayfa başına sorgu sayısı üst sınırı aşmaz (testlerde lazy loading zaten yasaktır, ihlal 500 verir).
 * - Harici kaynak: sayfada başka sunucuya giden src/href yoktur (CLAUDE.md bölüm 3 kural 1).
 * Yeni bir sayfa eklendiğinde bu tabloya da eklenir; eklenmezse test hata verir.
 */

const SWEEP_MAX_QUERIES = 30;

/** Sayfa (route URI) => 200 dönmesi gereken kullanıcılar. Diğerleri 403 veya 404 almalı. */
function sweepExpectations(): array
{
    $all = ['yonetici', 'operator1', 'depo', 'muhasebe'];

    return [
        '/' => $all,
        'hizli-islem' => $all,
        'overdue-receivables' => ['yonetici', 'muhasebe'],
        'stock-operation' => ['yonetici', 'depo'],
        'motor-olustur' => $all,
        'receteler' => ['yonetici', 'muhasebe'],
        'bom-templates' => ['yonetici', 'muhasebe'],
        'bom-templates/create' => ['yonetici'],
        'bom-templates/{record}/edit' => ['yonetici'],
        'ice-aktar' => ['yonetici'],
        'raporlar/uretim-adedi' => ['yonetici'],
        'raporlar/is-emri-performansi' => ['yonetici'],
        'raporlar/operator-uretimi' => ['yonetici'],
        'raporlar/siparis-durumu' => ['yonetici'],
        'raporlar/stok-bakiyeleri' => ['yonetici'],
        'raporlar/stok-hareketleri' => ['yonetici'],
        'denetim-izi' => ['yonetici'],
        'denetim-izi/{record}' => ['yonetici'],
        'users' => ['yonetici'],
        'users/create' => ['yonetici'],
        'users/{record}/edit' => ['yonetici'],
        'brands' => $all,
        'brands/create' => ['yonetici'],
        'brands/{record}/edit' => ['yonetici'],
        'part-subtypes' => $all,
        'part-subtypes/create' => ['yonetici'],
        'part-subtypes/{record}/edit' => ['yonetici'],
        'warehouses' => $all,
        'warehouses/create' => ['yonetici'],
        'warehouses/{record}/edit' => ['yonetici'],
        'units' => $all,
        // Operatör ve depo stokları görür, kart açıp düzenleyemez; hareketleri görür.
        'units/create' => ['yonetici'],
        'units/{record}/edit' => ['yonetici'],
        'parts' => $all,
        'parts/create' => ['yonetici'],
        'parts/{record}' => $all,
        'parts/{record}/edit' => ['yonetici'],
        'stock-movements' => ['yonetici', 'operator1', 'depo'],
        // Motor modeli, sipariş ve parti operatör ve depoya kapalı.
        // Tasarımlar: yönetici yönetir, muhasebe görür.
        'motor-designs' => ['yonetici', 'muhasebe'],
        'motor-designs/create' => ['yonetici'],
        'motor-designs/{record}' => ['yonetici', 'muhasebe'],
        'motor-designs/{record}/edit' => ['yonetici'],
        'tasarim-olustur' => ['yonetici'],
        'motor-models' => ['yonetici', 'muhasebe'],
        'motor-models/create' => ['yonetici'],
        'motor-models/{record}' => ['yonetici', 'muhasebe'],
        'motor-models/{record}/edit' => ['yonetici'],
        // Operatör ve depo cari hesapları görmez.
        'customers' => ['yonetici', 'muhasebe'],
        'customers/create' => ['yonetici', 'muhasebe'],
        'customers/{record}' => ['yonetici', 'muhasebe'],
        'customers/{record}/edit' => ['yonetici', 'muhasebe'],
        'orders' => ['yonetici', 'muhasebe'],
        'orders/create' => ['yonetici'],
        'orders/{record}' => ['yonetici', 'muhasebe'],
        'orders/{record}/edit' => ['yonetici'],
        'production-batches' => ['yonetici'],
        'production-batches/{record}/edit' => ['yonetici'],
        'work-orders' => ['yonetici', 'operator1', 'depo'],
        'work-orders/create' => ['yonetici'],
        'work-orders/{record}' => ['yonetici', 'operator1', 'depo'],
        'work-orders/{record}/edit' => ['yonetici'],
        'motor-units' => $all,
        'motor-units/{record}' => $all,
        'prices' => ['yonetici', 'muhasebe'],
        'prices/create' => ['yonetici', 'muhasebe'],
        'prices/{record}/edit' => ['yonetici', 'muhasebe'],
        'exchange-rates' => ['yonetici', 'muhasebe'],
        'exchange-rates/create' => ['yonetici', 'muhasebe'],
        'exchange-rates/{record}/edit' => ['yonetici', 'muhasebe'],
        // Faturaları yalnızca yönetici ve muhasebe görür; kesilmiş fatura düzenlenemez.
        'invoices' => ['yonetici', 'muhasebe'],
        'invoices/create' => ['yonetici', 'muhasebe'],
        'invoices/{record}' => ['yonetici', 'muhasebe'],
        'invoices/{record}/edit' => [],
        'payments' => ['yonetici', 'muhasebe'],
        'payments/create' => ['yonetici', 'muhasebe'],
        'payments/{record}' => ['yonetici', 'muhasebe'],
        'cash-accounts' => ['yonetici', 'muhasebe'],
        'cash-accounts/create' => ['yonetici', 'muhasebe'],
        'cash-accounts/{record}' => ['yonetici', 'muhasebe'],
        'cash-accounts/{record}/edit' => ['yonetici', 'muhasebe'],
        'cheques' => ['yonetici', 'muhasebe'],
    ];
}

/** {record} yerine konacak kayıtlar: düzenleme sayfaları için düzenlenebilir olanlar seçilir. */
function sweepRecords(): array
{
    $operator = User::query()->where('username', 'operator1')->value('id');

    return [
        'denetim-izi' => Activity::query()->value('id'),
        'users' => User::query()->where('username', 'operator2')->value('id'),
        'brands' => Brand::query()->value('id'),
        'warehouses' => Warehouse::query()->value('id'),
        'part-subtypes' => PartSubtype::query()->value('id'),
        'bom-templates' => BomTemplate::query()->value('id'),
        'units' => Unit::query()->value('id'),
        'parts' => Part::query()->where('code', DemoDataSeeder::MARKER_PART)->value('id'),
        'motor-designs' => MotorDesign::query()->value('id'),
        'motor-models' => MotorModel::query()->where('is_active', true)->value('id'),
        'customers' => Customer::query()->value('id'),
        'orders' => Order::query()->where('status', OrderStatus::Open->value)->value('id'),
        'production-batches' => ProductionBatch::query()->value('id'),
        'work-orders' => WorkOrder::query()->where('status', WorkOrderStatus::Planned->value)->where('assigned_user_id', $operator)->value('id'),
        'motor-units' => MotorUnit::query()->value('id'),
        'prices' => Price::query()->where('valid_from', '>', today())->value('id'),
        'exchange-rates' => ExchangeRate::query()->value('id'),
        'invoices' => Invoice::query()->where('direction', InvoiceDirection::Purchase->value)->value('id'),
        'payments' => Payment::query()->value('id'),
        'cash-accounts' => CashAccount::query()->value('id'),
    ];
}

// Demo görselleri geliştirme ortamının diskine yazılmasın.
beforeEach(function () {
    Storage::fake('local');

    // Örnek veri haftalık kurguludur: pazartesi erken saatte son haftanın adımları ileri tarihli kalıp atlanır.
    $this->travelTo(now()->startOfWeek()->addDays(2)->setTime(14, 0));
});

it('her panel sayfası her rolde yetki tablosuna uyar, sorgu sınırını aşmaz ve harici kaynak yüklemez', function () {
    $seeder = app(DemoDataSeeder::class);
    $seeder->weeks = 3;
    $seeder->run();

    $records = sweepRecords();
    expect(array_filter($records, fn ($id) => $id === null))->toBe([]);

    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RouteDefinition $route) => in_array('GET', $route->methods(), true)
            && str_starts_with((string) $route->getName(), 'filament.app.')
            && ! str_contains((string) $route->getName(), '.auth.'))
        ->map(fn (RouteDefinition $route) => $route->uri())
        ->values();

    $expectations = sweepExpectations();
    expect($routes->diff(array_keys($expectations))->values()->all())->toBe([])
        ->and(collect(array_keys($expectations))->diff($routes)->values()->all())->toBe([]);

    $failures = [];

    foreach (['yonetici', 'operator1', 'depo', 'muhasebe'] as $username) {
        $user = User::query()->where('username', $username)->firstOrFail();
        // Oturumda önceki kullanıcının şifre özeti kalırsa AuthenticateSession oturumu kapatır.
        $this->flushSession();

        foreach ($routes as $uri) {
            $path = str_replace('{record}', (string) ($records[explode('/', $uri)[0]] ?? ''), $uri);

            app('auth')->forgetGuards();
            $this->actingAs($user);

            DB::flushQueryLog();
            DB::enableQueryLog();
            $response = $this->get('/'.ltrim($path, '/'));
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            $status = $response->getStatusCode();
            $allowed = in_array($username, $expectations[$uri], true);

            if ($allowed && $status !== 200) {
                $failures[] = "{$username} {$uri}: 200 bekleniyordu, {$status} geldi";
            } elseif (! $allowed && ! in_array($status, [403, 404], true)) {
                $failures[] = "{$username} {$uri}: 403/404 bekleniyordu, {$status} geldi";
            }

            if ($status === 200) {
                if ($queries > SWEEP_MAX_QUERIES) {
                    $failures[] = "{$username} {$uri}: {$queries} sorgu (sınır ".SWEEP_MAX_QUERIES.')';
                }

                if (preg_match('#(?:src|href)\s*=\s*["\'](?:https?:)?//(?!localhost[:/"\'])[^"\']+#i', (string) $response->getContent(), $match)) {
                    $failures[] = "{$username} {$uri}: harici kaynak {$match[0]}";
                }
            }
        }
    }

    expect($failures)->toBe([]);
});

it('operatör başkasına atanmış iş emrinin sayfasını açar ama başlatamaz', function () {
    $seeder = app(DemoDataSeeder::class);
    $seeder->weeks = 2;
    $seeder->run();

    $other = WorkOrder::query()
        ->where('assigned_user_id', User::query()->where('username', 'operator2')->value('id'))
        ->where('status', WorkOrderStatus::Planned->value)
        ->first() ?? WorkOrder::query()->where('assigned_user_id', User::query()->where('username', 'operator2')->value('id'))->firstOrFail();

    $operator = User::query()->where('username', 'operator1')->firstOrFail();
    $this->actingAs($operator)->get("/work-orders/{$other->getKey()}")->assertOk();

    expect(app(WorkOrderService::class)->canTransition($operator, $other, WorkOrderStatus::InProgress))->toBeFalse();
});
