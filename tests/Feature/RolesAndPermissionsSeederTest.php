<?php

use App\Enums\Permission;
use App\Enums\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role as RoleModel;

it('enumlardaki tüm rolleri ve yetkileri oluşturur', function () {
    expect(RoleModel::pluck('name')->sort()->values()->all())
        ->toBe(collect(Role::cases())->map->value->sort()->values()->all())
        ->and(PermissionModel::count())->toBe(count(Permission::cases()));

    foreach (Role::cases() as $role) {
        expect(RoleModel::findByName($role->value)->permissions->pluck('name')->sort()->values()->all())
            ->toBe(collect($role->permissions())->map->value->sort()->values()->all());
    }
});

it('tekrar çalıştırılabilir (idempotent) ve bozulan eşleşmeyi düzeltir', function () {
    RoleModel::findByName(Role::Warehouse->value)->revokePermissionTo(Permission::StockMove->value);

    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(RolesAndPermissionsSeeder::class);

    expect(RoleModel::count())->toBe(count(Role::cases()))
        ->and(PermissionModel::count())->toBe(count(Permission::cases()))
        ->and(RoleModel::findByName(Role::Warehouse->value)->hasPermissionTo(Permission::StockMove->value))->toBeTrue();
});

it('yetki tablosunu (CLAUDE.md bölüm 6) uygular', function () {
    expect(Role::Admin->permissions())->toBe(Permission::cases())
        ->and(Role::Operator->permissions())->not->toContain(Permission::StockMove, Permission::WorkOrdersManage, Permission::PartsManage, Permission::UsersManage)
        ->and(Role::Operator->permissions())->toContain(Permission::WorkOrdersView, Permission::WorkOrdersChangeStatus)
        ->and(Role::Operator->permissions())->not->toContain(Permission::WorkOrdersChangeStatusAny)
        // Depo iş emirlerini salt okunur görür.
        ->and(Role::Warehouse->permissions())->toContain(Permission::WorkOrdersView, Permission::StockMove, Permission::PartsManage, Permission::ReportsStock)
        ->and(Role::Warehouse->permissions())->not->toContain(Permission::WorkOrdersManage, Permission::WorkOrdersChangeStatus, Permission::WorkOrdersChangeStatusAny, Permission::ReportsProduction, Permission::UsersManage);
});
