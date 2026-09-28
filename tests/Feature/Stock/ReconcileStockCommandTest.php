<?php

use App\Models\Part;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

it('tutarlı bakiyede başarıyla biter ve bildirim göndermez', function () {
    $admin = User::factory()->admin()->create();
    Part::factory()->withStock(5)->create();
    Part::factory()->create();

    $this->artisan('stock:reconcile')
        ->expectsOutput('Stok bakiyeleri tutarlı.')
        ->assertSuccessful();

    expect($admin->notifications()->count())->toBe(0);
});

it('tutarsızlığı yakalar, loglar ve yöneticiye bildirir; düzeltme yapmaz', function () {
    $admin = User::factory()->admin()->create();
    $warehouse = User::factory()->warehouse()->create();
    $ok = Part::factory()->withStock(5)->create();
    $broken = Part::factory()->withStock(5)->create(['code' => 'BOZUK-1']);

    // Servis dışından bakiye bozulur (ör. elle SQL).
    DB::table('stock_balances')->where('part_id', $broken->id)->update(['quantity' => 7]);

    Log::spy();

    $this->artisan('stock:reconcile')
        ->expectsOutputToContain('1 parçada fark bulundu')
        ->assertFailed();

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context) => str_contains($message, 'stock:reconcile')
        && $context['mismatches'][0]['code'] === 'BOZUK-1'
        && $context['mismatches'][0]['balance'] === '7.000'
        && $context['mismatches'][0]['movements_total'] === '5.000');

    $notification = $admin->notifications()->sole();
    expect($notification->data['title'])->toContain('1 parçada fark')
        ->and($notification->data['body'])->toContain('BOZUK-1')
        ->and($warehouse->notifications()->count())->toBe(0)
        // Otomatik düzeltme yok.
        ->and((string) $broken->stockBalance()->value('quantity'))->toBe('7.000');
});

it('bakiye satırı olmayan parçayı da yakalar', function () {
    $part = Part::factory()->create();
    DB::table('stock_balances')->where('part_id', $part->id)->delete();

    $this->artisan('stock:reconcile')->assertFailed();
});

it('günlük olarak zamanlanmıştır', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains($event->command, 'stock:reconcile'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 3 * * *');
});
