<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ProductionBatch;
use App\Models\User;

/**
 * Üretim partileri: yalnızca yönetici (iş emri yönetimi yetkisi) görür ve yönetir (CLAUDE.md bölüm 6).
 */
class ProductionBatchPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can(Permission::WorkOrdersManage);
    }

    public function view(User $actor, ProductionBatch $batch): bool
    {
        return $actor->can(Permission::WorkOrdersManage);
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
