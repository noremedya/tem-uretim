<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\CashAccount;
use App\Models\User;

/** Ön muhasebe (tahsilat/ödeme, kasa/banka, çek/senet, cari ekstre): yönetici ve muhasebe. */
class CashAccountPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can(Permission::FinanceManage);
    }

    public function view(User $actor, CashAccount $cashAccount): bool
    {
        return $actor->can(Permission::FinanceManage);
    }

    public function create(User $actor): bool
    {
        return $actor->can(Permission::FinanceManage);
    }

    public function update(User $actor, CashAccount $cashAccount): bool
    {
        return $actor->can(Permission::FinanceManage);
    }

    public function delete(User $actor, CashAccount $cashAccount): bool
    {
        return false;
    }

    public function deleteAny(User $actor): bool
    {
        return false;
    }
}
