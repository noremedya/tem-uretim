<?php

/*
 * Gerçek eşzamanlılık testleri: iki ayrı PHP süreci aynı anda StockService'i çağırır.
 *
 * Veri, işçi süreçlerin görebilmesi için commit edilmelidir; bu yüzden transaction'lı RefreshDatabase
 * kullanılmaz. stock_movements TRUNCATE'e kapalı olduğundan DatabaseTruncation da kullanılamaz; temizlik
 * migrate:fresh (DROP TABLE) ile yapılır. Trigger'ı atlatan bir yol bilerek yoktur.
 *
 * Senaryo (tests/Concurrency/helpers.php): test süreci bakiye satırını kilitler, iki işçi başlatılır ve
 * ikisinin de kilit beklediği görülünce kilit bırakılır. Böylece iki işlem gerçekten aynı anda yarışır.
 */

use App\Models\Part;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\StockService;
use Illuminate\Support\Str;

require_once __DIR__.'/helpers.php';

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

    $results = raceWorkers(fn () => StockBalance::query()->where('part_id', $part->id)->lockForUpdate()->first(), [
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

    $results = raceWorkers(fn () => StockBalance::query()->where('part_id', $part->id)->lockForUpdate()->first(), [
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

    $results = raceWorkers(fn () => StockBalance::query()->where('part_id', $part->id)->lockForUpdate()->first(), [
        ['correct', (string) $part->id, (string) $this->user->id, (string) $movement->id],
        ['correct', (string) $part->id, (string) $this->user->id, (string) $movement->id],
    ]);

    expect(collect($results)->where('ok', true))->toHaveCount(1)
        ->and(collect($results)->firstWhere('ok', false)['message'])->toBe('Bu hareket zaten ters kaydedilmiş.')
        ->and(StockMovement::where('corrected_movement_id', $movement->id)->count())->toBe(1)
        ->and((string) $part->stockBalance()->value('quantity'))->toBe('5.000');
});
