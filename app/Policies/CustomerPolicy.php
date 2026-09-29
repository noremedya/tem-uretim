<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Customer;
use App\Models\User;

class CustomerPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can(Permission::CustomersView);
    }

    public function view(User $actor, Customer $customer): bool
    {
        return $actor->can(Permission::CustomersView);
    }

    public function create(User $actor): bool
    {
        return $actor->can(Permission::CustomersManage);
    }

    public function update(User $actor, Customer $customer): bool
    {
        return $actor->can(Permission::CustomersManage);
    }

    public function deactivate(User $actor, Customer $customer): bool
    {
        return $actor->can(Permission::CustomersManage) && $customer->is_active;
    }

    public function activate(User $actor, Customer $customer): bool
    {
        return $actor->can(Permission::CustomersManage) && ! $customer->is_active;
    }

    // Silme yok, pasifleştirme var.
    public function delete(User $actor, Customer $customer): bool
    {
        return false;
    }

    public function deleteAny(User $actor): bool
    {
        return false;
    }

    public function restore(User $actor, Customer $customer): bool
    {
        return false;
    }

    public function forceDelete(User $actor, Customer $customer): bool
    {
        return false;
    }

    public function forceDeleteAny(User $actor): bool
    {
        return false;
    }
}
