<?php

/*
 * Eşzamanlılık testleri için ayrı süreçte çalışan işçi. Kendi veritabanı bağlantısıyla StockService'i çağırır
 * ve sonucu JSON olarak yazar.
 *
 * Kullanım: php worker.php <in|out|correct> <part_id> <user_id> <miktar|hareket_id> [idempotency_key]
 */

use App\Exceptions\BusinessRuleException;
use App\Models\Part;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\StockService;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $operation, $partId, $userId, $argument] = $argv;
$key = $argv[5] ?? null;

$service = app(StockService::class);
$part = Part::query()->findOrFail($partId);
$user = User::query()->findOrFail($userId);

try {
    $movement = match ($operation) {
        'in' => $service->stockIn($part, $argument, $user, null, $key),
        'out' => $service->stockOut($part, $argument, $user, 'Eşzamanlı çıkış', $key),
        'correct' => $service->correct(StockMovement::query()->findOrFail($argument), $user, 'Eşzamanlı ters kayıt'),
    };

    echo json_encode(['ok' => true, 'id' => $movement->id, 'created' => $movement->wasRecentlyCreated]);
} catch (BusinessRuleException $exception) {
    echo json_encode(['ok' => false, 'error' => class_basename($exception), 'message' => $exception->getMessage()]);
}
