<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\MotorDesign;
use App\Models\User;

/** Tasarımlar: motor modeliyle aynı yetki — görüntüleme motor modeli görme, değiştirme motor modeli yönetme. Silinmez. */
class MotorDesignPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can(Permission::MotorModelsView);
    }

    public function view(User $actor, MotorDesign $design): bool
    {
        return $actor->can(Permission::MotorModelsView);
    }

    public function create(User $actor): bool
    {
        return $actor->can(Permission::MotorModelsManage);
    }

    public function update(User $actor, MotorDesign $design): bool
    {
        return $actor->can(Permission::MotorModelsManage);
    }

    public function deactivate(User $actor, MotorDesign $design): bool
    {
        return $actor->can(Permission::MotorModelsManage) && $design->is_active;
    }

    public function activate(User $actor, MotorDesign $design): bool
    {
        return $actor->can(Permission::MotorModelsManage) && ! $design->is_active;
    }

    public function delete(User $actor, MotorDesign $design): bool
    {
        return false;
    }

    public function deleteAny(User $actor): bool
    {
        return false;
    }
}
