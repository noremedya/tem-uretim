<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;
use App\Models\Warehouse;

/** Depolar: ürünleri gören görür, ürünleri yöneten (yönetici) yönetir. Silinmez. */
class WarehousePolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can(Permission::PartsView);
    }

    public function view(User $actor, Warehouse $warehouse): bool
    {
        return $actor->can(Permission::PartsView);
    }

    public function create(User $actor): bool
    {
        return $actor->can(Permission::PartsManage);
    }

    public function update(User $actor, Warehouse $warehouse): bool
    {
        return $actor->can(Permission::PartsManage);
    }

    public function deactivate(User $actor, Warehouse $warehouse): bool
    {
        return $actor->can(Permission::PartsManage) && $warehouse->is_active;
    }

    public function activate(User $actor, Warehouse $warehouse): bool
    {
        return $actor->can(Permission::PartsManage) && ! $warehouse->is_active;
    }

    public function delete(User $actor, Warehouse $warehouse): bool
    {
        return false;
    }

    public function deleteAny(User $actor): bool
    {
        return false;
    }
}
