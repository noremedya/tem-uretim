<?php

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role as RoleModel;

it('seçeneklerle yönetici oluşturur', function () {
    $this->artisan('app:create-admin', [
        '--username' => ' Yonetici ',
        '--name' => 'Ayşe Yönetici',
        '--password' => 'gizli-sifre',
        '--no-interaction' => true,
    ])->assertSuccessful();

    $user = User::where('username', 'yonetici')->sole();

    expect($user->name)->toBe('Ayşe Yönetici')
        ->and($user->email)->toBeNull()
        ->and($user->is_active)->toBeTrue()
        ->and($user->hasRole(Role::Admin))->toBeTrue()
        ->and(Hash::check('gizli-sifre', $user->password))->toBeTrue();
});

it('etkileşimli olarak soruları sorar', function () {
    $this->artisan('app:create-admin')
        ->expectsQuestion('Kullanıcı adı', 'ali')
        ->expectsQuestion('Ad soyad', 'Ali Veli')
        ->expectsQuestion('E-posta (isteğe bağlı)', 'Ali@Example.com')
        ->expectsQuestion('Şifre', 'gizli-sifre')
        ->expectsQuestion('Şifre (tekrar)', 'gizli-sifre')
        ->assertSuccessful();

    expect(User::where('username', 'ali')->sole())
        ->email->toBe('ali@example.com')
        ->hasRole(Role::Admin)->toBeTrue();
});

it('şifreler uyuşmazsa kullanıcı oluşturmaz', function () {
    $this->artisan('app:create-admin')
        ->expectsQuestion('Kullanıcı adı', 'ali')
        ->expectsQuestion('Ad soyad', 'Ali Veli')
        ->expectsQuestion('E-posta (isteğe bağlı)', '')
        ->expectsQuestion('Şifre', 'gizli-sifre')
        ->expectsQuestion('Şifre (tekrar)', 'baska-sifre')
        ->assertFailed();

    expect(User::count())->toBe(0);
});

it('kullanılan kullanıcı adını ve geçersiz biçimi reddeder', function (string $username) {
    User::factory()->create(['username' => 'mevcut']);

    $this->artisan('app:create-admin', [
        '--username' => $username,
        '--name' => 'Test',
        '--password' => 'gizli-sifre',
        '--no-interaction' => true,
    ])->assertFailed();

    expect(User::count())->toBe(1);
})->with(['mevcut', 'MEVCUT', 'ab', 'boşluk var', 'şükrü']);

it('roller yoksa anlaşılır hata verir', function () {
    RoleModel::query()->delete();

    $this->artisan('app:create-admin', ['--no-interaction' => true])
        ->expectsOutputToContain('RolesAndPermissionsSeeder')
        ->assertFailed();
});
