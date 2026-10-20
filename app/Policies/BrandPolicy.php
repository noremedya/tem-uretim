<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Brand;
use App\Models\User;

/** Markalar: ürünleri gören görür, ürünleri yöneten (yönetici) yönetir. Silinmez. */
class BrandPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can(Permission::PartsView);
    }

    public function view(User $actor, Brand $brand): bool
    {
        return $actor->can(Permission::PartsView);
    }

    public function create(User $actor): bool
    {
        return $actor->can(Permission::PartsManage);
    }

    public function update(User $actor, Brand $brand): bool
    {
        return $actor->can(Permission::PartsManage);
    }

    public function deactivate(User $actor, Brand $brand): bool
    {
        return $actor->can(Permission::PartsManage) && $brand->is_active;
    }

    public function activate(User $actor, Brand $brand): bool
    {
        return $actor->can(Permission::PartsManage) && ! $brand->is_active;
    }

    public function delete(User $actor, Brand $brand): bool
    {
        return false;
    }

    public function deleteAny(User $actor): bool
    {
        return false;
    }
}
