<?php

use App\Enums\Role;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
});

it('formdan birden fazla rolle kullanıcı oluşturur', function () {
    Livewire::test(CreateUser::class)
        ->fillForm([
            'username' => 'Ahmet.Usta',
            'name' => 'Ahmet Usta',
            'email' => null,
            'roles' => [Role::Operator->value, Role::Warehouse->value],
            'password' => 'gizli-sifre',
            'password_confirmation' => 'gizli-sifre',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $user = User::where('username', 'ahmet.usta')->sole();
    expect($user->getRoleNames()->sort()->values()->all())->toBe(['operator', 'warehouse'])
        ->and($user->email)->toBeNull()
        ->and(Hash::check('gizli-sifre', $user->password))->toBeTrue();
});

it('rol seçilmeden ve geçersiz kullanıcı adıyla kayıt yapılmaz', function () {
    Livewire::test(CreateUser::class)
        ->fillForm([
            'username' => 'şü',
            'name' => 'X',
            'roles' => [],
            'password' => 'gizli-sifre',
            'password_confirmation' => 'gizli-sifre',
        ])
        ->call('create')
        ->assertHasFormErrors(['username', 'roles']);

    expect(User::count())->toBe(1);
});

it('düzenlemede şifre boş bırakılırsa değişmez', function () {
    $user = User::factory()->operator()->create(['password' => 'eski-sifre']);

    Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])
        ->assertSchemaStateSet(['roles' => [Role::Operator]])
        ->fillForm(['name' => 'Yeni ad', 'password' => '', 'password_confirmation' => ''])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($user->fresh())
        ->name->toBe('Yeni ad')
        ->and(Hash::check('eski-sifre', $user->fresh()->password))->toBeTrue();
});

it('son aktif yönetici kendi yönetici rolünü formdan kaldıramaz', function () {
    Livewire::test(EditUser::class, ['record' => $this->admin->getRouteKey()])
        ->fillForm(['roles' => [Role::Operator->value]])
        ->call('save')
        ->assertNotified('İşlem yapılamadı');

    expect($this->admin->fresh()->getRoleNames()->all())->toBe(['admin']);
});

it('listeden kullanıcı pasifleştirilir ve aktifleştirilir; kendini pasifleştirme aksiyonu görünmez', function () {
    $user = User::factory()->operator()->create();

    Livewire::test(ListUsers::class)
        ->assertActionHidden(TestAction::make('deactivate')->table($this->admin))
        ->callAction(TestAction::make('deactivate')->table($user))
        ->assertNotified('Kullanıcı pasifleştirildi');

    expect($user->fresh()->is_active)->toBeFalse();

    Livewire::test(ListUsers::class)
        ->filterTable('is_active', false)
        ->callAction(TestAction::make('activate')->table($user))
        ->assertNotified('Kullanıcı aktifleştirildi');

    expect($user->fresh()->is_active)->toBeTrue();
});

it('düzenleme sayfasından pasifleştirme sonrası form güncel sürümle kaydedilebilir', function () {
    $user = User::factory()->operator()->create();

    Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])
        ->callAction('deactivate')
        ->fillForm(['name' => 'Pasif kullanıcı'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($user->fresh())
        ->is_active->toBeFalse()
        ->name->toBe('Pasif kullanıcı');
});
