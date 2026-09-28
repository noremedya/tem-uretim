<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Unit;
use App\Models\User;

class UnitPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can(Permission::PartsView);
    }

    public function view(User $actor, Unit $unit): bool
    {
        return $actor->can(Permission::PartsView);
    }

    public function create(User $actor): bool
    {
        return $actor->can(Permission::PartsManage);
    }

    public function update(User $actor, Unit $unit): bool
    {
        return $actor->can(Permission::PartsManage);
    }

    // Birim silinmez (parçalar FK ile bağlı).
    public function delete(User $actor, Unit $unit): bool
    {
        return false;
    }

    public function deleteAny(User $actor): bool
    {
        return false;
    }
}
