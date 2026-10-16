<?php

/*
 * Sipariş kalemi kotası eşzamanlılık testi: üç süreç aynı anda aynı kaleme (5 adet) 2'şer adetlik iş emri açar.
 * Kalem satırı kilitlenerek kontrol edildiği için en fazla iki iş emri açılabilir; kota aşılmaz.
 */

use App\Models\Order;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProductionFixtures;

require_once __DIR__.'/helpers.php';

beforeEach(fn () => freshDatabase());

afterEach(fn () => freshDatabase());

it('aynı anda açılan iş emirleri sipariş kalemi kotasını aşamaz', function () {
    $admin = User::factory()->admin()->create();
    $f = ProductionFixtures::create();
    $item = Order::factory()->open()->withItem(5, $f->model)->create()->items()->sole();

    $args = ['work_order', (string) $f->model->finished_part_id, (string) $item->id, (string) $admin->id, '2'];

    $results = collect(raceWorkers(
        fn () => DB::table('order_items')->where('id', $item->id)->lockForUpdate()->first(),
        [$args, $args, $args],
    ));

    expect($results->where('ok', true))->toHaveCount(2)
        ->and($results->where('ok', false)->sole()['message'])->toContain('kotası aşılıyor')
        ->and(WorkOrder::where('order_item_id', $item->id)->sum('quantity'))->toBe('4.000')
        ->and(WorkOrder::pluck('work_order_number')->unique())->toHaveCount(2);
});
