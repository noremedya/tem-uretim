<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\PartSubtype;
use App\Models\User;

/** Alt türler: ürünleri gören görür, ürünleri yöneten (yönetici) yönetir. Silinmez. */
class PartSubtypePolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can(Permission::PartsView);
    }

    public function view(User $actor, PartSubtype $subtype): bool
    {
        return $actor->can(Permission::PartsView);
    }

    public function create(User $actor): bool
    {
        return $actor->can(Permission::PartsManage);
    }

    public function update(User $actor, PartSubtype $subtype): bool
    {
        return $actor->can(Permission::PartsManage);
    }

    public function deactivate(User $actor, PartSubtype $subtype): bool
    {
        return $actor->can(Permission::PartsManage) && $subtype->is_active;
    }

    public function activate(User $actor, PartSubtype $subtype): bool
    {
        return $actor->can(Permission::PartsManage) && ! $subtype->is_active;
    }

    public function delete(User $actor, PartSubtype $subtype): bool
    {
        return false;
    }

    public function deleteAny(User $actor): bool
    {
        return false;
    }
}
