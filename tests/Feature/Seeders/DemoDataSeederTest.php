<?php

use App\Enums\ChequeStatus;
use App\Enums\MotorUnitStatus;
use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Enums\WorkOrderStatus;
use App\Models\Cheque;
use App\Models\Invoice;
use App\Models\MotorUnit;
use App\Models\Order;
use App\Models\Part;
use App\Models\Payment;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\StatementService;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

// Demo görselleri geliştirme ortamının diskine yazılmasın.
beforeEach(fn () => Storage::fake('local'));

it('demo veri servislerle, kurallara uygun ve tutarlı yüklenir', function () {
    app(DemoDataSeeder::class)->run();

    // Her rolden kullanıcı, ortak demo şifresiyle; biri pasif.
    foreach ([Role::Admin, Role::Operator, Role::Warehouse, Role::Accounting] as $role) {
        expect(User::role($role->value)->where('is_active', true)->exists())->toBeTrue();
    }
    expect(Hash::check(DemoDataSeeder::PASSWORD, User::query()->where('username', 'yonetici')->value('password')))->toBeTrue()
        ->and(User::query()->where('username', 'operator3')->value('is_active'))->toBeFalse();

    // Her durumda iş emri, sipariş ve motor.
    expect(WorkOrder::query()->distinct()->pluck('status')->map->value->sort()->values()->all())
        ->toBe(collect(WorkOrderStatus::cases())->map->value->sort()->values()->all());
    expect(Order::query()->where('status', OrderStatus::Completed->value)->exists())->toBeTrue()
        ->and(Order::query()->where('status', OrderStatus::Cancelled->value)->exists())->toBeTrue();
    foreach (MotorUnitStatus::cases() as $status) {
        expect(MotorUnit::query()->where('status', $status->value)->exists())->toBeTrue();
    }

    // Gecikmiş iş emri, kritik ve hareketsiz stok, vadesi geçmiş alacak: dashboard dolu görünsün.
    expect(WorkOrder::query()->where('status', WorkOrderStatus::InProgress->value)->whereDate('planned_end', '<', today())->exists())->toBeTrue()
        ->and(Part::query()->critical()->exists())->toBeTrue()
        ->and(app(StatementService::class)->overdueReceivables())->not->toBeEmpty();
    $this->artisan('stock:idle-report')->assertSuccessful();

    // Ön muhasebe: kesilmiş satış ve alış faturaları, tahsilat/ödeme, çek akışları.
    expect(Invoice::query()->where('direction', 'sales')->where('status', 'issued')->exists())->toBeTrue()
        ->and(Invoice::query()->where('direction', 'purchase')->where('status', 'issued')->exists())->toBeTrue()
        ->and(Payment::query()->distinct()->count('method'))->toBeGreaterThanOrEqual(4)
        ->and(Cheque::query()->distinct()->pluck('status')->map->value->all())->toContain(ChequeStatus::Portfolio->value, ChequeStatus::Endorsed->value, ChequeStatus::Paid->value);

    // Denetim izinde işlemi yapan kullanıcı görünür; saat ve oturum seeder sonunda geri alınır.
    expect(Activity::query()->whereNotNull('causer_id')->count())->toBeGreaterThan(Activity::query()->whereNull('causer_id')->count())
        ->and(Auth::check())->toBeFalse()
        ->and(now()->diffInMinutes(Carbon::createFromTimestamp(time())))->toBeLessThan(1);

    // Hareket toplamları bakiyelerle, mamul bakiyeleri stoktaki motor sayısıyla tutarlı.
    $this->artisan('stock:reconcile')->expectsOutputToContain('tutarlı')->assertSuccessful();
});

it('demo veri ikinci kez yüklenmez', function () {
    $seeder = app(DemoDataSeeder::class);
    $seeder->weeks = 1;
    $seeder->run();
    $parts = Part::query()->count();

    $seeder->run();

    expect(Part::query()->count())->toBe($parts);
});

it('demo veri üretim ortamında çalışmaz', function () {
    app()->detectEnvironment(fn () => 'production');

    expect(fn () => app(DemoDataSeeder::class)->run())->toThrow(RuntimeException::class, 'üretim ortamında');
    expect(Part::query()->where('code', DemoDataSeeder::MARKER_PART)->exists())->toBeFalse();
});
