<?php

/*
 * Sipariş numarası eşzamanlılık testi: üç ayrı PHP süreci aynı anda sipariş oluşturur. Test süreci sayaç satırını
 * kilitli tutar; üç işçi de kilidi beklerken bırakılır. Numaralar çakışmamalı ve boşluksuz olmalıdır.
 */

use App\Models\Customer;
use App\Models\MotorModel;
use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/helpers.php';

beforeEach(fn () => freshDatabase());

afterEach(fn () => freshDatabase());

it('aynı anda oluşturulan siparişler çakışmasız ve sıralı numara alır', function () {
    $customer = Customer::factory()->create();
    $model = MotorModel::factory()->create();
    $year = today()->format('Y');

    // Sayaç satırı oluşsun (ilk sipariş).
    $first = app(OrderService::class)->create([
        'customer_id' => $customer->id,
        'due_date' => today()->addMonth()->toDateString(),
        'items' => [['motor_model_id' => $model->id, 'quantity' => 1]],
    ]);
    expect($first->order_number)->toBe("SIP-{$year}-00001");

    $args = ['order', (string) $customer->id, (string) $model->id];

    $results = raceWorkers(
        fn () => DB::table('number_sequences')->where('name', 'order')->lockForUpdate()->first(),
        [$args, $args, $args],
    );

    expect(collect($results)->every(fn ($r) => $r['ok']))->toBeTrue()
        ->and(collect($results)->pluck('number')->sort()->values()->all())
        ->toBe(["SIP-{$year}-00002", "SIP-{$year}-00003", "SIP-{$year}-00004"])
        ->and(Order::count())->toBe(4);
});
