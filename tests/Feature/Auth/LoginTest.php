<?php

use App\Filament\Pages\Auth\Login;
use App\Http\Middleware\LogoutInactiveUser;
use App\Models\User;
use App\Services\UserService;
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

it('Türkçe karakterli ve büyük harfli kullanıcı adıyla da giriş yapılır', function () {
    $user = User::factory()->operator()->create(['username' => 'sukru.ozgur', 'password' => 'gizli-sifre']);

    Livewire::test(Login::class)
        ->fillForm(['username' => 'ŞÜKRÜ.Özgür', 'password' => 'gizli-sifre'])
        ->call('authenticate')
        ->assertHasNoFormErrors();

    $this->assertAuthenticatedAs($user);
});

it('oturumu açıkken pasifleştirilen kullanıcı sonraki istekte çıkışa zorlanır ve mesaj görür', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->operator()->create();

    $this->actingAs($user)->get('/')->assertOk();

    app(UserService::class)->deactivate($user, $admin);

    $this->get('/')->assertRedirect('/login');
    $this->assertGuest();

    $this->get('/login')
        ->assertOk()
        ->assertSee(LogoutInactiveUser::MESSAGE);

    // Mesaj yalnızca bir kez gösterilir.
    $this->get('/login')->assertDontSee(LogoutInactiveUser::MESSAGE);
});

it('aktif kullanıcı çıkışa zorlanmaz ve mesaj görmez', function () {
    $user = User::factory()->operator()->create();

    $this->actingAs($user)->get('/')->assertOk();
    $this->assertAuthenticatedAs($user);
});

it('çıkış middleware\'i Livewire isteklerinde de çalışır (persistent)', function () {
    expect(Livewire::getPersistentMiddleware())->toContain(LogoutInactiveUser::class);
});
