<?php

it('panel logosu ve sekme simgesi ayardan gelir', function () {
    $this->get('/login')->assertOk()
        ->assertSee('images/tem-uretim-logo.svg')
        ->assertSee('images/tem-uretim-icon.svg');
});

it('başka firma kurulumunda logo değişir, boşsa ad yazı olarak görünür', function () {
    config(['app.brand_logo' => 'images/baska-logo']);
    $this->get('/login')->assertOk()->assertSee('images/baska-logo.svg')->assertDontSee('tem-uretim-logo');

    config(['app.brand_logo' => null]);
    $this->get('/login')->assertOk()->assertDontSee('tem-uretim-logo')->assertSee(config('app.name'));
});

it('logo dosyaları yereldir, harici kaynak ve betik taşımaz', function () {
    foreach (['tem-uretim-logo.svg', 'tem-uretim-logo-dark.svg', 'tem-uretim-icon.svg'] as $file) {
        expect(file_get_contents(public_path("images/{$file}")))
            ->toContain('<svg')->not->toContain('<script')->not->toContain('href=')->not->toContain('<text');
    }
});
