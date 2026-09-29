<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\MotorModel;
use App\Models\User;

class MotorModelPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can(Permission::MotorModelsView);
    }

    public function view(User $actor, MotorModel $model): bool
    {
        return $actor->can(Permission::MotorModelsView);
    }

    public function create(User $actor): bool
    {
        return $actor->can(Permission::MotorModelsManage);
    }

    public function update(User $actor, MotorModel $model): bool
    {
        return $actor->can(Permission::MotorModelsManage);
    }

    public function deactivate(User $actor, MotorModel $model): bool
    {
        return $actor->can(Permission::MotorModelsManage) && $model->is_active;
    }

    public function activate(User $actor, MotorModel $model): bool
    {
        return $actor->can(Permission::MotorModelsManage) && ! $model->is_active;
    }

    // Silme yok, pasifleştirme var.
    public function delete(User $actor, MotorModel $model): bool
    {
        return false;
    }

    public function deleteAny(User $actor): bool
    {
        return false;
    }

    public function restore(User $actor, MotorModel $model): bool
    {
        return false;
    }

    public function forceDelete(User $actor, MotorModel $model): bool
    {
        return false;
    }

    public function forceDeleteAny(User $actor): bool
    {
        return false;
    }
}
