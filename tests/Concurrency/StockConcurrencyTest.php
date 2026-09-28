<?php

/*
 * Gerçek eşzamanlılık testleri: iki ayrı PHP süreci aynı anda StockService'i çağırır.
 *
 * Veri, işçi süreçlerin görebilmesi için commit edilmelidir; bu yüzden transaction'lı RefreshDatabase
 * kullanılmaz. stock_movements TRUNCATE'e kapalı olduğundan DatabaseTruncation da kullanılamaz; temizlik
 * migrate:fresh (DROP TABLE) ile yapılır. Trigger'ı atlatan bir yol bilerek yoktur.
 *
 * Senaryo: test süreci bakiye satırını kilitler, iki işçi başlatılır ve ikisinin de kilit beklediği
 * görülünce kilit bırakılır. Böylece iki işlem gerçekten aynı anda yarışır.
 */

use App\Models\Part;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\StockService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\TestCase;

function freshDatabase(): void
{
    TestCase::ensureTestDatabase();

    Artisan::call('migrate:fresh', ['--seed' => true, '--seeder' => RolesAndPermissionsSeeder::class]);
}

/**
 * Bakiye satırını kilitli tutarken işçileri başlatır, hepsi kilit bekleyince kilidi bırakır.
 *
 * @param  list<list<string>>  $workerArgs
 * @return list<array<string, mixed>>
 */
function raceWorkers(Part $part, array $workerArgs): array
{
    DB::beginTransaction();
    StockBalance::query()->where('part_id', $part->id)->lockForUpdate()->first();

    $env = [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => 'pgsql',
        'DB_HOST' => config('database.connections.pgsql.host'),
        'DB_PORT' => (string) config('database.connections.pgsql.port'),
        'DB_DATABASE' => config('database.connections.pgsql.database'),
        'DB_USERNAME' => config('database.connections.pgsql.username'),
        'DB_PASSWORD' => config('database.connections.pgsql.password'),
        'QUEUE_CONNECTION' => 'sync',
        'CACHE_STORE' => 'array',
    ];

    /** @var list<InvokedProcess> $processes */
    $processes = array_map(
        fn (array $args) => Process::env($env)->timeout(60)->start([PHP_BINARY, base_path('tests/Concurrency/worker.php'), ...$args]),
        $workerArgs,
    );

    // İşçilerin hepsi bakiye kilidini bekleyene kadar (en fazla 20 sn) bekle.
    $deadline = microtime(true) + 20;
    do {
        usleep(50_000);
        // pg_locks canlıdır; pg_stat_activity transaction içinde anlık görüntü döndürdüğü için kullanılmaz.
        $waiting = DB::connection()->selectOne(
            'SELECT count(DISTINCT pid) AS c FROM pg_locks WHERE NOT granted AND pid <> pg_backend_pid()'
        )->c;
    } while ($waiting < count($processes) && microtime(true) < $deadline);

    expect($waiting)->toBe(count($processes), 'İşçiler kilit beklemeye başlamadı');

    DB::commit();

    return array_map(function (InvokedProcess $process): array {
        $result = $process->wait();
        expect($result->successful())->toBeTrue($result->errorOutput());

        return json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);
    }, $processes);
}

beforeEach(function () {
    freshDatabase();
    $this->user = User::factory()->warehouse()->create();
});

afterEach(function () {
    // Commit edilen veriyi temizle (DROP; TRUNCATE trigger'ına takılmaz).
    freshDatabase();
});

it('aynı anda iki çıkış bakiyeyi eksiye düşüremez', function () {
    $part = Part::factory()->withStock(10, $this->user)->create();

    $results = raceWorkers($part, [
        ['out', (string) $part->id, (string) $this->user->id, '7'],
        ['out', (string) $part->id, (string) $this->user->id, '7'],
    ]);

    expect(collect($results)->where('ok', true))->toHaveCount(1)
        ->and(collect($results)->where('error', 'InsufficientStockException'))->toHaveCount(1)
        ->and((string) $part->stockBalance()->value('quantity'))->toBe('3.000')
        ->and(StockMovement::where('part_id', $part->id)->count())->toBe(2);

    $this->artisan('stock:reconcile')->assertSuccessful();
});

it('aynı anda aynı gönderim anahtarıyla iki giriş tek hareket oluşturur', function () {
    $part = Part::factory()->create();
    $key = (string) Str::uuid();

    $results = raceWorkers($part, [
        ['in', (string) $part->id, (string) $this->user->id, '5', $key],
        ['in', (string) $part->id, (string) $this->user->id, '5', $key],
    ]);

    expect(collect($results)->every(fn ($r) => $r['ok']))->toBeTrue()
        ->and(collect($results)->pluck('id')->unique())->toHaveCount(1)
        ->and(collect($results)->where('created', true))->toHaveCount(1)
        ->and((string) $part->stockBalance()->value('quantity'))->toBe('5.000')
        ->and(StockMovement::where('idempotency_key', $key)->count())->toBe(1);
});

it('aynı hareket aynı anda iki kez ters kaydedilemez', function () {
    $part = Part::factory()->create();
    $movement = app(StockService::class)->stockIn($part, 5, $this->user);
    app(StockService::class)->stockIn($part, 5, $this->user);

    $results = raceWorkers($part, [
        ['correct', (string) $part->id, (string) $this->user->id, (string) $movement->id],
        ['correct', (string) $part->id, (string) $this->user->id, (string) $movement->id],
    ]);

    expect(collect($results)->where('ok', true))->toHaveCount(1)
        ->and(collect($results)->firstWhere('ok', false)['message'])->toBe('Bu hareket zaten ters kaydedilmiş.')
        ->and(StockMovement::where('corrected_movement_id', $movement->id)->count())->toBe(1)
        ->and((string) $part->stockBalance()->value('quantity'))->toBe('5.000');
});
