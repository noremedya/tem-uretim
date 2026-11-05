<?php

/*
 * Seri no eşzamanlılık testi: aynı modelin iki iş emri iki ayrı süreçte aynı anda tamamlanır. Test süreci seri no
 * sayaç satırını kilitli tutar; ikisi de beklerken bırakılır. Seri numaraları çakışmamalı ve boşluksuz olmalıdır.
 */

use App\Models\MotorUnit;
use App\Models\User;
use App\Services\ProductionService;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProductionFixtures;

require_once __DIR__.'/helpers.php';

beforeEach(fn () => freshDatabase());

afterEach(fn () => freshDatabase());

it('aynı anda tamamlanan iş emirlerinin seri numaraları çakışmaz', function () {
    $admin = User::factory()->admin()->create();
    $f = ProductionFixtures::create()->stockUp($admin);
    $prefix = 'TM-71-'.now()->format('ymd');

    // Sayaç satırı oluşsun (ilk tamamlama: 00001–00002).
    app(ProductionService::class)->complete($f->startedMotorWorkOrder($admin, 2), $admin);

    $first = $f->startedMotorWorkOrder($admin, 3);
    $second = $f->startedMotorWorkOrder($admin, 3);

    $results = collect(raceWorkers(
        fn () => DB::table('number_sequences')->where('name', 'serial_number')->lockForUpdate()->first(),
        [['complete', (string) $first->id, (string) $admin->id], ['complete', (string) $second->id, (string) $admin->id]],
    ));

    expect($results->every(fn ($r) => $r['ok']))->toBeTrue();

    $serials = MotorUnit::orderBy('serial_number')->pluck('serial_number');
    expect($serials)->toHaveCount(8)
        ->and($serials->unique())->toHaveCount(8)
        ->and($serials->all())->toBe(array_map(fn (int $i) => sprintf('%s-%05d', $prefix, $i), range(1, 8)))
        ->and((string) $f->model->finishedPart->defaultStockBalance()->value('quantity'))->toBe('8.000');
});
