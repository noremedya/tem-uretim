<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ProductionBatch;
use App\Models\User;

/**
 * Üretim partileri: yönetici yönetir, iş emri görebilen herkes görüntüler (CLAUDE.md bölüm 6).
 */
class ProductionBatchPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can(Permission::WorkOrdersView);
    }

    public function view(User $actor, ProductionBatch $batch): bool
    {
        return $actor->can(Permission::WorkOrdersView);
    }

    public function create(User $actor): bool
    {
        return $actor->can(Permission::WorkOrdersManage);
    }

    public function update(User $actor, ProductionBatch $batch): bool
    {
        return $actor->can(Permission::WorkOrdersManage);
    }

    // Parti silinmez (iş emirleri ve motorlar bağlanır).
    public function delete(User $actor, ProductionBatch $batch): bool
    {
        return false;
    }

    public function deleteAny(User $actor): bool
    {
        return false;
    }
}
