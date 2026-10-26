<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\BomTemplate;
use App\Models\User;

/** Reçete şablonları reçeteyle aynı yetkiyle: görüntüleme motor modeli görme, yönetme motor modeli yönetme. */
class BomTemplatePolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can(Permission::MotorModelsView);
    }

    public function view(User $actor, BomTemplate $template): bool
    {
        return $actor->can(Permission::MotorModelsView);
    }

    public function create(User $actor): bool
    {
        return $actor->can(Permission::MotorModelsManage);
    }

    public function update(User $actor, BomTemplate $template): bool
    {
        return $actor->can(Permission::MotorModelsManage);
    }

    public function deactivate(User $actor, BomTemplate $template): bool
    {
        return $actor->can(Permission::MotorModelsManage) && $template->is_active;
    }

    public function activate(User $actor, BomTemplate $template): bool
    {
        return $actor->can(Permission::MotorModelsManage) && ! $template->is_active;
    }

    public function delete(User $actor, BomTemplate $template): bool
    {
        return false;
    }

    public function deleteAny(User $actor): bool
    {
        return false;
    }
}
