<?php

use App\Enums\Permission;
use App\Enums\Role;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Livewire\Livewire;

it('panel kök adreste çalışır; misafir giriş sayfasına yönlendirilir', function () {
    $this->get('/')->assertRedirect('/login');
    expect(UserResource::getUrl('index', isAbsolute: false))->toBe('/users');
});

it('yönetici kullanıcı ekranlarına erişir', function () {
    $admin = User::factory()->admin()->create();
    $other = User::factory()->operator()->create();

    $this->actingAs($admin);
    $this->get(UserResource::getUrl('index'))->assertOk()->assertSee($other->username);
    $this->get(UserResource::getUrl('create'))->assertOk();
    $this->get(UserResource::getUrl('edit', ['record' => $other]))->assertOk();
});

it('operatör ve depo kullanıcı ekranlarına erişemez', function (string $state) {
    $user = User::factory()->{$state}()->create();
    $other = User::factory()->operator()->create();

    $this->actingAs($user);
    $this->get('/')->assertOk();
    $this->get(UserResource::getUrl('index'))->assertForbidden();
    $this->get(UserResource::getUrl('create'))->assertForbidden();
    $this->get(UserResource::getUrl('edit', ['record' => $other]))->assertForbidden();
})->with(['operator', 'warehouse']);

it('kullanıcı silinemez', function () {
    $admin = User::factory()->admin()->create();
    $other = User::factory()->operator()->create();

    expect($admin->can('delete', $other))->toBeFalse()
        ->and($admin->can('forceDelete', $other))->toBeFalse()
        ->and($admin->can('deleteAny', User::class))->toBeFalse();

    $this->actingAs($admin);
    Livewire::test(EditUser::class, ['record' => $other->getRouteKey()])
        ->assertActionDoesNotExist('delete');
});

it('birden fazla rolün yetkileri birleşir', function () {
    $user = User::factory()->withRoles(Role::Operator, Role::Warehouse)->create();

    expect($user->can(Permission::WorkOrdersChangeStatus))->toBeTrue()
        ->and($user->can(Permission::StockMove))->toBeTrue()
        ->and($user->can(Permission::UsersManage))->toBeFalse();
});
