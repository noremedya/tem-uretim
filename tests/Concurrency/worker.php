<?php

/*
 * Eşzamanlılık testleri için ayrı süreçte çalışan işçi. Kendi veritabanı bağlantısıyla servisi çağırır
 * ve sonucu JSON olarak yazar.
 *
 * Kullanım:
 *   php worker.php <in|out|correct> <part_id> <user_id> <miktar|hareket_id> [idempotency_key]
 *   php worker.php order <customer_id> <motor_model_id>
 */

use App\Exceptions\BusinessRuleException;
use App\Models\Part;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\OrderService;
use App\Services\StockService;
use Illuminate\Contracts\Console\Kernel;
use Tests\TestCase;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// Yalnızca test veritabanında çalışır (tests/TestCase.php ile aynı kural).
TestCase::ensureTestDatabase();

$operation = $argv[1];

try {
    if ($operation === 'order') {
        [, , $customerId, $motorModelId] = $argv;

        $order = app(OrderService::class)->create([
            'customer_id' => (int) $customerId,
            'due_date' => today()->addMonth()->toDateString(),
            'items' => [['motor_model_id' => (int) $motorModelId, 'quantity' => 1]],
        ]);

        echo json_encode(['ok' => true, 'id' => $order->id, 'number' => $order->order_number]);

        exit;
    }

    [, , $partId, $userId, $argument] = $argv;
    $key = $argv[5] ?? null;

    $service = app(StockService::class);
    $part = Part::query()->findOrFail($partId);
    $user = User::query()->findOrFail($userId);

    $movement = match ($operation) {
        'in' => $service->stockIn($part, $argument, $user, null, $key),
        'out' => $service->stockOut($part, $argument, $user, 'Eşzamanlı çıkış', $key),
        'correct' => $service->correct(StockMovement::query()->findOrFail($argument), $user, 'Eşzamanlı ters kayıt'),
    };

    echo json_encode(['ok' => true, 'id' => $movement->id, 'created' => $movement->wasRecentlyCreated]);
} catch (BusinessRuleException $exception) {
    echo json_encode(['ok' => false, 'error' => class_basename($exception), 'message' => $exception->getMessage()]);
}
