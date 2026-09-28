<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

it('testler test veritabanına bağlı çalışır', function () {
    expect(DB::connection()->getDatabaseName())->toBe(TestCase::TEST_DATABASE);

    TestCase::ensureTestDatabase(); // hata fırlatmaz
});

it('bağlı veritabanı test veritabanı değilse koruma hata verir', function () {
    config([
        'database.connections.guard_probe' => array_merge(config('database.connections.pgsql'), ['database' => 'tem_uretim']),
        'database.default' => 'guard_probe',
    ]);

    try {
        expect(fn () => TestCase::ensureTestDatabase())
            ->toThrow(RuntimeException::class, 'Testler yalnızca "tem_uretim_test" veritabanında çalışır; bağlı veritabanı "tem_uretim"');
    } finally {
        config(['database.default' => 'pgsql']);
    }
});

it('yanlış veritabanıyla başlatılan test çalıştırması migrate:fresh çalıştırmadan durur', function () {
    // Var olmayan bir veritabanı adı kullanılır: koruma çalışmasaydı bile hiçbir veri silinemezdi.
    // Koruma bağlantı kurulmadan devreye girdiği için çıktıda bağlantı hatası değil, koruma mesajı görünür.
    $result = Process::env([
        'DB_DATABASE' => 'tem_uretim_guard_probe',
        'DB_URL' => '',
    ])->timeout(120)->run([PHP_BINARY, base_path('vendor/bin/pest'), base_path('tests/Feature/SetupTest.php')]);

    expect($result->failed())->toBeTrue()
        ->and($result->output())
        ->toContain('Testler yalnızca "tem_uretim_test" veritabanında çalışır; bağlı veritabanı "tem_uretim_guard_probe"')
        ->not->toContain('SQLSTATE')
        ->not->toContain('Dropping all tables')
        ->not->toContain('Tests:    0 failed');
});
