<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

/** Denetim izi: yalnızca yönetici görüntüler; kayıtlar değiştirilmez ve silinmez. */
class ActivityPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can(Permission::AuditLogView);
    }

    public function view(User $actor, Activity $activity): bool
    {
        return $actor->can(Permission::AuditLogView);
    }

    public function create(User $actor): bool
    {
        return false;
    }

    public function update(User $actor, Activity $activity): bool
    {
        return false;
    }

    public function delete(User $actor, Activity $activity): bool
    {
        return false;
    }

    public function deleteAny(User $actor): bool
    {
        return false;
    }
}
