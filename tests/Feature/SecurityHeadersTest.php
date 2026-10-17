<?php

use App\Http\Middleware\SecurityHeaders;
use App\Models\User;
use Illuminate\Foundation\Vite;

it('her yanıtta güvenlik başlıkları ve yalnızca kendi sunucumuza izin veren CSP bulunur', function (string $path, bool $authenticated) {
    if ($authenticated) {
        $this->actingAs(User::factory()->admin()->create());
    }

    $response = $this->get($path);

    $response->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('Referrer-Policy', 'same-origin');

    expect($response->headers->get('Permissions-Policy'))->toContain('camera=(self)');

    $csp = $response->headers->get('Content-Security-Policy');
    expect($csp)->toContain("default-src 'self'")
        ->toContain("object-src 'none'")
        ->toContain("frame-ancestors 'self'")
        // Hiçbir yönergede harici sunucu (http/https adresi) yok.
        ->not->toMatch('#https?://#');
})->with([
    'giriş sayfası' => ['/login', false],
    'panel' => ['/', true],
    'sağlık kontrolü' => ['/up', false],
]);

it('geliştirmede Vite sunucusu çalışıyorsa CSP ona da izin verir', function () {
    $hot = storage_path('framework/testing/vite.hot');
    @mkdir(dirname($hot), 0777, true);
    file_put_contents($hot, 'http://localhost:5173');

    try {
        // Testler Vite'ı sahtesiyle değiştirir (withoutVite); gerçek Vite örneği kullanılır.
        $csp = (new SecurityHeaders((new Vite)->useHotFile($hot)))->contentSecurityPolicy();
    } finally {
        @unlink($hot);
    }

    expect($csp)->toContain("script-src 'self' http://localhost:5173")
        ->toContain('connect-src \'self\' http://localhost:5173 ws://localhost:5173');
});
