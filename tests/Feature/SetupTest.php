<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;

it('testleri PostgreSQL üzerinde çalıştırır', function () {
    expect(DB::connection()->getDriverName())->toBe('pgsql')
        ->and(DB::connection()->getDatabaseName())->toBe('tem_uretim_test');
});

it('Türkçe dil, İstanbul saat dilimi ve Türkçe sayı biçimi kullanır', function () {
    expect(app()->getLocale())->toBe('tr')
        ->and(config('app.timezone'))->toBe('Europe/Istanbul')
        ->and(Number::format(1234.5, precision: 2))->toBe('1.234,50');
});

it('giriş sayfasında harici (CDN) kaynak yoktur', function () {
    $html = $this->get('/login')->assertOk()->getContent();

    preg_match_all('/(?:src|href)\s*=\s*"(https?:\/\/[^"]+)"/i', $html, $matches);

    $external = collect($matches[1])
        ->reject(fn (string $url) => str_starts_with($url, config('app.url')))
        ->values()
        ->all();

    expect($external)->toBe([]);
});

it('sağlık kontrolü /up çalışır', function () {
    $this->get('/up')->assertOk();
});
