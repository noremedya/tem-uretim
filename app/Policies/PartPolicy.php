<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Part;
use App\Models\User;

class PartPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can(Permission::PartsView);
    }

    public function view(User $actor, Part $part): bool
    {
        return $actor->can(Permission::PartsView);
    }

    public function create(User $actor): bool
    {
        return $actor->can(Permission::PartsManage);
    }

    public function update(User $actor, Part $part): bool
    {
        return $actor->can(Permission::PartsManage);
    }

    public function deactivate(User $actor, Part $part): bool
    {
        return $actor->can(Permission::PartsManage) && $part->is_active;
    }

    public function activate(User $actor, Part $part): bool
    {
        return $actor->can(Permission::PartsManage) && ! $part->is_active;
    }

    // Silme yok, pasifleştirme var.
    public function delete(User $actor, Part $part): bool
    {
        return false;
    }

    public function deleteAny(User $actor): bool
    {
        return false;
    }

    public function restore(User $actor, Part $part): bool
    {
        return false;
    }

    public function forceDelete(User $actor, Part $part): bool
    {
        return false;
    }

    public function forceDeleteAny(User $actor): bool
    {
        return false;
    }
}
