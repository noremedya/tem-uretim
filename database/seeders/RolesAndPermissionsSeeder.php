<?php

namespace Database\Seeders;

use App\Enums\Permission;
use App\Enums\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role as RoleModel;
use Spatie\Permission\PermissionRegistrar;

/**
 * Rolleri ve yetkileri App\Enums\Role / App\Enums\Permission'dan üretir.
 * Tekrar çalıştırılabilir: eksikleri ekler, rol-yetki eşleşmesini enum'daki hâline getirir.
 * Enum'da artık olmayan yetkileri silmez (elle incelenmeli).
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        DB::transaction(function (): void {
            foreach (Permission::cases() as $permission) {
                PermissionModel::findOrCreate($permission->value, 'web');
            }

            foreach (Role::cases() as $role) {
                RoleModel::findOrCreate($role->value, 'web')
                    ->syncPermissions(array_map(fn (Permission $p) => $p->value, $role->permissions()));
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
