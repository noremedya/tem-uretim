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
    // Operatör ve depo için görmeleri yasak olan her şey.
    $hidden = [
        Permission::MotorModelsView, Permission::OrdersView, Permission::CustomersView, Permission::InvoicesViewPurchase,
        Permission::InvoicesManage, Permission::FinanceManage, Permission::ReportsProduction, Permission::ReportsStock,
        Permission::PartsManage, Permission::WorkOrdersManage, Permission::WorkOrdersChangeStatusAny, Permission::UsersManage,
        Permission::AuditLogView,
    ];

    expect(Role::Admin->permissions())->toBe(Permission::cases())
        // Herkes "Motor oluştur" ekranıyla yeni motor oluşturur (motor_models.build).
        // Operatör: stokları, hareketleri, tüm iş emirlerini ve motorları görür; kendi iş emrini başlatıp tamamlar, sevk eder.
        ->and(Role::Operator->permissions())->toEqualCanonicalizing([
            Permission::MotorModelsBuild, Permission::WorkOrdersView, Permission::WorkOrdersViewAll, Permission::WorkOrdersChangeStatus,
            Permission::MotorUnitsView, Permission::MotorUnitsShip, Permission::PartsView, Permission::StockMovementsView,
        ])
        // Depo: aynı görünürlük; stok işlemi ve sevk yapar, iş emri durumu değiştirmez.
        ->and(Role::Warehouse->permissions())->toEqualCanonicalizing([
            Permission::MotorModelsBuild, Permission::WorkOrdersView, Permission::WorkOrdersViewAll, Permission::MotorUnitsView, Permission::MotorUnitsShip, Permission::MotorUnitsReturn,
            Permission::PartsView, Permission::StockMove, Permission::StockMovementsView,
        ]);

    foreach ([Role::Operator, Role::Warehouse] as $role) {
        expect(array_intersect(array_map(fn ($p) => $p->value, $role->permissions()), array_map(fn ($p) => $p->value, $hidden)))->toBe([]);
    }
});
