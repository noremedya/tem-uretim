<?php

use App\Models\Part;
use App\Models\User;
use App\Services\StockService;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->stock = app(StockService::class);
    $this->admin = User::factory()->admin()->create();
    $this->warehouse = User::factory()->warehouse()->create();
    $this->operator = User::factory()->operator()->create();
    $this->inactiveAdmin = User::factory()->admin()->inactive()->create();
});

function criticalNotificationCount(User $user): int
{
    return $user->notifications()->where('data->title', 'like', 'Kritik stok:%')->count();
}

it('bakiye kritik seviyeye inince yönetici ve depoya bir kez bildirim gider', function () {
    $part = Part::factory()->critical(10)->withStock(20, $this->warehouse)->create();

    $this->stock->stockOut($part, 5, $this->warehouse, 'Çıkış');   // 15: kritik değil
    expect(criticalNotificationCount($this->admin))->toBe(0);

    $this->stock->stockOut($part, 5, $this->warehouse, 'Çıkış');   // 10: eşit → kritik
    $this->stock->stockOut($part, 3, $this->warehouse, 'Çıkış');   // 7: hâlâ kritik, tekrar yok
    $this->stock->stockIn($part, 2, $this->warehouse);              // 9: hâlâ kritik, tekrar yok

    expect(criticalNotificationCount($this->admin))->toBe(1)
        ->and(criticalNotificationCount($this->warehouse))->toBe(1)
        ->and(criticalNotificationCount($this->operator))->toBe(0)
        ->and(criticalNotificationCount($this->inactiveAdmin))->toBe(0)
        ->and($part->stockBalance()->value('is_below_critical'))->toBeTrue();

    $body = $this->admin->notifications()->first()->data['body'];
    expect($body)->toContain('Bakiye 10')->toContain('kritik seviye 10');
});

it('bakiye kritik seviyenin üstüne çıkınca yeniden bildirim gönderilebilir', function () {
    $part = Part::factory()->critical(10)->withStock(12, $this->warehouse)->create();

    $this->stock->stockOut($part, 4, $this->warehouse, 'Çıkış');    // 8: bildirim 1
    $this->stock->stockIn($part, 2, $this->warehouse);               // 10: eşit, hâlâ kritik
    expect($part->stockBalance()->value('is_below_critical'))->toBeTrue();

    $this->stock->stockIn($part, 5, $this->warehouse);               // 15: üstünde → sıfırlanır
    expect($part->stockBalance()->value('is_below_critical'))->toBeFalse();

    $this->stock->adjustCount($part, 3, $this->warehouse, 'Sayım'); // 3: bildirim 2

    expect(criticalNotificationCount($this->admin))->toBe(2);
});

it('kritik seviye 0 ise takip yoktur, bildirim gitmez', function () {
    $part = Part::factory()->critical(0)->withStock(5, $this->warehouse)->create();

    $this->stock->stockOut($part, 5, $this->warehouse, 'Hepsi çıktı');

    expect(criticalNotificationCount($this->admin))->toBe(0)
        ->and($part->stockBalance()->value('is_below_critical'))->toBeFalse();
});

it('geri alınan işlem bildirim göndermez', function () {
    $part = Part::factory()->critical(10)->withStock(20, $this->warehouse)->create();

    try {
        DB::transaction(function () use ($part) {
            $this->stock->stockOut($part, 15, $this->warehouse, 'Çıkış');
            throw new RuntimeException('dış işlem başarısız');
        });
    } catch (RuntimeException) {
    }

    expect(criticalNotificationCount($this->admin))->toBe(0)
        ->and((string) $part->stockBalance()->value('quantity'))->toBe('20.000')
        ->and($part->stockBalance()->value('is_below_critical'))->toBeFalse();
});
