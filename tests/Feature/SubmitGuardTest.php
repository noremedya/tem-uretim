<?php

use App\Filament\Pages\StockOperation;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Vite;
use Livewire\Livewire;

it('stok işlemi formu submitGuard ile korunur', function () {
    $this->actingAs(User::factory()->warehouse()->create());

    Livewire::test(StockOperation::class)
        ->assertSeeHtml('<form x-data="submitGuard({ action: \'save\' })" id="form" wire:submit="save"');
});

it('ortak Alpine bileşenleri panelde Vite ile yüklenir (CDN yok)', function () {
    // Derlenmiş varlıklara bağımlı olmamak için Vite "hot" moduna alınır.
    $hotFile = storage_path('framework/testing/vite.hot');
    File::put($hotFile, 'http://vite.test');
    $this->withVite();
    Vite::useHotFile($hotFile);

    try {
        $this->actingAs(User::factory()->warehouse()->create());

        $this->get(StockOperation::getUrl())
            ->assertOk()
            ->assertSee('http://vite.test/resources/js/filament/app.js', escape: false);
    } finally {
        File::delete($hotFile);
    }
});

it('submitGuard JS mantık testleri geçer', function () {
    // Node'un yerleşik test çalıştırıcısı (ek paket yok); TAP çıktısı sürümden bağımsız okunur.
    $result = Process::path(base_path())->timeout(60)
        ->run(['node', '--test', '--test-reporter=tap', 'tests/js/submit-guard.test.mjs']);

    expect($result->successful())->toBeTrue($result->output().$result->errorOutput())
        ->and($result->output())->toContain('# pass 5')->toContain('# fail 0');
});
