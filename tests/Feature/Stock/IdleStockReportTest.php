<?php

use App\Filament\Resources\Parts\Pages\ListParts;
use App\Models\Part;
use App\Models\User;
use App\Services\StockService;
use Illuminate\Console\Scheduling\Schedule;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->warehouse = User::factory()->warehouse()->create();
    $this->operator = User::factory()->operator()->create();
});

/** $daysAgo gün önce giriş hareketi almış parça. */
function partMovedDaysAgo(int $daysAgo, array $attributes = [], string $quantity = '5'): Part
{
    test()->travelTo(now()->subDays($daysAgo));
    $part = Part::factory()->withStock($quantity, test()->warehouse)->create($attributes);
    test()->travelBack();

    return $part;
}

it('90+ gündür hareketsiz parçaları tek özet bildirimle yönetici ve depoya bildirir', function () {
    $idle = partMovedDaysAgo(100, ['code' => 'HM-ESKI']);
    partMovedDaysAgo(10, ['code' => 'HM-YENI']);
    partMovedDaysAgo(120, ['code' => 'PASIF'])->forceFill(['is_active' => false])->save();

    // Bakiyesi 0 olan eski parça hareketsiz sayılmaz.
    $empty = partMovedDaysAgo(120, ['code' => 'BOS']);
    $this->travelTo(now()->subDays(119));
    app(StockService::class)->stockOut($empty, '5', $this->warehouse, 'Tümü çıktı');
    $this->travelBack();

    $this->artisan('stock:idle-report')
        ->expectsOutputToContain('1 parça 90 gündür hareket görmedi')
        ->assertSuccessful();

    foreach ([$this->admin, $this->warehouse] as $user) {
        $notification = $user->notifications()->sole();
        expect($notification->data['title'])->toBe('Hareketsiz stok: 1 parça 90 gündür hareket görmedi')
            ->and($notification->data['body'])->toContain('HM-ESKI')
            ->toContain(now()->subDays(100)->format('d.m.Y'))
            ->not->toContain('HM-YENI')
            ->not->toContain('PASIF')
            ->not->toContain('BOS');
    }

    expect($this->operator->notifications()->count())->toBe(0);
});

it('hareketsiz parça yoksa bildirim göndermez', function () {
    partMovedDaysAgo(30);

    $this->artisan('stock:idle-report')
        ->expectsOutput('90 gündür hareketsiz parça yok.')
        ->assertSuccessful();

    expect($this->admin->notifications()->count())->toBe(0);
});

it('eşik ayardan değiştirilebilir', function () {
    config(['stock.idle_days' => 20]);
    partMovedDaysAgo(30, ['code' => 'OTUZ']);

    $this->artisan('stock:idle-report')->assertSuccessful();

    expect($this->admin->notifications()->sole()->data['body'])->toContain('OTUZ');
});

it('10\'dan fazla parçada listeyi kısaltır', function () {
    foreach (range(1, 12) as $i) {
        partMovedDaysAgo(100, ['code' => sprintf('P-%02d', $i)]);
    }

    $this->artisan('stock:idle-report')->assertSuccessful();

    expect($this->admin->notifications()->sole()->data['body'])
        ->toContain('P-10')
        ->not->toContain('P-11')
        ->toContain('… ve 2 parça daha.');
});

it('her pazartesi 08:00\'de zamanlanmıştır', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains($event->command, 'stock:idle-report'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 8 * * 1');
});

it('parça listesinde hareketsiz filtresi çalışır', function () {
    $this->actingAs($this->warehouse);
    $idle = partMovedDaysAgo(100);
    $recent = partMovedDaysAgo(5);

    Livewire::test(ListParts::class)
        ->filterTable('idle')
        ->assertCanSeeTableRecords([$idle])
        ->assertCanNotSeeTableRecords([$recent]);
});
