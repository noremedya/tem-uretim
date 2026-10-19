<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\BomItem;
use App\Models\User;

/**
 * Reçete: görüntüleme motor modeli görüntüleme yetkisiyle (operatör ve depo görmez); yönetim motor modeli
 * yönetme yetkisiyle (CLAUDE.md bölüm 6).
 */
class BomItemPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can(Permission::MotorModelsView);
    }

    public function view(User $actor, BomItem $item): bool
    {
        return $this->viewAny($actor);
    }

    public function create(User $actor): bool
    {
        return $actor->can(Permission::MotorModelsManage);
    }

    public function update(User $actor, BomItem $item): bool
    {
        return $actor->can(Permission::MotorModelsManage);
    }

    public function delete(User $actor, BomItem $item): bool
    {
        return $actor->can(Permission::MotorModelsManage);
    }

    public function deleteAny(User $actor): bool
    {
        return false;
    }
}
