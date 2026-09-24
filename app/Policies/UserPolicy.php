<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;

class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can(Permission::UsersManage);
    }

    public function view(User $actor, User $user): bool
    {
        return $actor->can(Permission::UsersManage);
    }

    public function create(User $actor): bool
    {
        return $actor->can(Permission::UsersManage);
    }

    public function update(User $actor, User $user): bool
    {
        return $actor->can(Permission::UsersManage);
    }

    /** Kullanıcı kendini pasifleştiremez; son yönetici kuralı UserService'te. */
    public function deactivate(User $actor, User $user): bool
    {
        return $actor->can(Permission::UsersManage) && $user->is_active && ! $user->is($actor);
    }

    public function activate(User $actor, User $user): bool
    {
        return $actor->can(Permission::UsersManage) && ! $user->is_active;
    }

    // Silme yok, pasifleştirme var.
    public function delete(User $actor, User $user): bool
    {
        return false;
    }

    public function deleteAny(User $actor): bool
    {
        return false;
    }

    public function restore(User $actor, User $user): bool
    {
        return false;
    }

    public function forceDelete(User $actor, User $user): bool
    {
        return false;
    }

    public function forceDeleteAny(User $actor): bool
    {
        return false;
    }
}
