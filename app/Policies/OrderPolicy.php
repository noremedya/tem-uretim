<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Order;
use App\Models\User;

class OrderPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can(Permission::OrdersView);
    }

    public function view(User $actor, Order $order): bool
    {
        return $actor->can(Permission::OrdersView);
    }

    public function create(User $actor): bool
    {
        return $actor->can(Permission::OrdersManage);
    }

    /** Tamamlanan ve iptal edilen sipariş düzenlenemez (OrderService'te de kontrol edilir). */
    public function update(User $actor, Order $order): bool
    {
        return $actor->can(Permission::OrdersManage) && $order->status->isEditable();
    }

    public function cancel(User $actor, Order $order): bool
    {
        return $actor->can(Permission::OrdersManage) && $order->status->isCancellable();
    }

    // Sipariş silinmez; iptal edilir.
    public function delete(User $actor, Order $order): bool
    {
        return false;
    }

    public function deleteAny(User $actor): bool
    {
        return false;
    }

    public function restore(User $actor, Order $order): bool
    {
        return false;
    }

    public function forceDelete(User $actor, Order $order): bool
    {
        return false;
    }

    public function forceDeleteAny(User $actor): bool
    {
        return false;
    }
}
