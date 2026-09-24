<?php

use App\Filament\Pages\Auth\Login;
use App\Models\User;
use Livewire\Livewire;

it('kullanıcı adıyla giriş yapılır (büyük/küçük harf ve boşluk fark etmez)', function () {
    $user = User::factory()->operator()->create(['username' => 'ahmet', 'password' => 'gizli-sifre']);

    Livewire::test(Login::class)
        ->fillForm(['username' => ' Ahmet ', 'password' => 'gizli-sifre'])
        ->call('authenticate')
        ->assertHasNoFormErrors();

    $this->assertAuthenticatedAs($user);
});

it('giriş formunda e-posta değil kullanıcı adı alanı vardır', function () {
    Livewire::test(Login::class)
        ->assertFormFieldExists('username')
        ->assertFormFieldDoesNotExist('email');
});

it('yanlış şifreyle giriş yapılamaz', function () {
    User::factory()->operator()->create(['username' => 'ahmet', 'password' => 'gizli-sifre']);

    Livewire::test(Login::class)
        ->fillForm(['username' => 'ahmet', 'password' => 'yanlis-sifre'])
        ->call('authenticate')
        ->assertHasFormErrors(['username']);

    $this->assertGuest();
});

it('pasif kullanıcı giriş yapamaz', function () {
    User::factory()->operator()->inactive()->create(['username' => 'ahmet', 'password' => 'gizli-sifre']);

    Livewire::test(Login::class)
        ->fillForm(['username' => 'ahmet', 'password' => 'gizli-sifre'])
        ->call('authenticate')
        ->assertHasFormErrors(['username']);

    $this->assertGuest();
});

it('oturumu açıkken pasifleştirilen kullanıcı panele erişemez', function () {
    $user = User::factory()->operator()->create();
    $this->actingAs($user)->get('/')->assertOk();

    $user->forceFill(['is_active' => false])->save();

    $this->actingAs($user->fresh())->get('/')->assertForbidden();
});
