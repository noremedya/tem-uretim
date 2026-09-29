<?php

/*
 * Eşzamanlılık testleri için ortak yardımcılar (dosya adı *Test.php olmadığından Pest bunu test olarak çalıştırmaz).
 * Veri commit edilir; temizlik migrate:fresh (DROP TABLE) ile yapılır.
 */

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

function freshDatabase(): void
{
    TestCase::ensureTestDatabase();

    Artisan::call('migrate:fresh', ['--seed' => true, '--seeder' => RolesAndPermissionsSeeder::class]);
}

/**
 * $lock ile bir satırı kilitli tutarken işçileri başlatır, hepsi kilit bekleyince kilidi bırakır.
 * Böylece işçiler gerçekten aynı anda yarışır.
 *
 * @param  Closure(): mixed  $lock  Açık transaction içinde kilidi alan sorgu.
 * @param  list<list<string>>  $workerArgs
 * @return list<array<string, mixed>>
 */
function raceWorkers(Closure $lock, array $workerArgs): array
{
    DB::beginTransaction();
    $lock();

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

    // İşçilerin hepsi kilidi bekleyene kadar (en fazla 20 sn) bekle.
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
