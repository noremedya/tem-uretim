<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\WorkOrderStatus;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\WorkOrderService;

/**
 * İş emri yetkileri (CLAUDE.md bölüm 6). Görünürlük: yönetici ve depo tümünü, operatör yalnızca kendine
 * atananları görür (WorkOrder::scopeVisibleTo). Durum geçişi kuralları WorkOrderService::canTransition'da.
 */
class WorkOrderPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can(Permission::WorkOrdersView);
    }

    public function view(User $actor, WorkOrder $workOrder): bool
    {
        return $actor->can(Permission::WorkOrdersView)
            && ($actor->can(Permission::WorkOrdersViewAll) || $workOrder->isAssignedTo($actor));
    }

    public function create(User $actor): bool
    {
        return $actor->can(Permission::WorkOrdersManage);
    }

    public function update(User $actor, WorkOrder $workOrder): bool
    {
        return $actor->can(Permission::WorkOrdersManage) && $workOrder->status->isOpen();
    }

    public function refreshMaterials(User $actor, WorkOrder $workOrder): bool
    {
        return $actor->can(Permission::WorkOrdersManage) && $workOrder->status === WorkOrderStatus::Planned;
    }

    public function start(User $actor, WorkOrder $workOrder): bool
    {
        return $this->transitions()->canTransition($actor, $workOrder, WorkOrderStatus::InProgress)
            && $workOrder->status === WorkOrderStatus::Planned;
    }

    public function revertToPlanned(User $actor, WorkOrder $workOrder): bool
    {
        return $workOrder->status === WorkOrderStatus::InProgress
            && $this->transitions()->canTransition($actor, $workOrder, WorkOrderStatus::Planned);
    }

    public function cancel(User $actor, WorkOrder $workOrder): bool
    {
        return $this->transitions()->canTransition($actor, $workOrder, WorkOrderStatus::Cancelled);
    }

    // İş emri silinmez; iptal edilir.
    public function delete(User $actor, WorkOrder $workOrder): bool
    {
        return false;
    }

    public function deleteAny(User $actor): bool
    {
        return false;
    }

    public function restore(User $actor, WorkOrder $workOrder): bool
    {
        return false;
    }

    public function forceDelete(User $actor, WorkOrder $workOrder): bool
    {
        return false;
    }

    public function forceDeleteAny(User $actor): bool
    {
        return false;
    }

    private function transitions(): WorkOrderService
    {
        return app(WorkOrderService::class);
    }
}
