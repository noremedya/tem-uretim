<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\AccountTransaction;
use App\Models\User;

/** Ön muhasebe (tahsilat/ödeme, kasa/banka, çek/senet, cari ekstre): yönetici ve muhasebe. */
class AccountTransactionPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can(Permission::FinanceManage);
    }

    public function view(User $actor, AccountTransaction $accountTransaction): bool
    {
        return $actor->can(Permission::FinanceManage);
    }

    public function create(User $actor): bool
    {
        // Elle oluşturulmaz: çek/senet tahsilat/ödemeyle, cari hareketi belgelerle oluşur.
        return false;
    }

    public function update(User $actor, AccountTransaction $accountTransaction): bool
    {
        return false;
    }

    public function delete(User $actor, AccountTransaction $accountTransaction): bool
    {
        return false;
    }

    public function deleteAny(User $actor): bool
    {
        return false;
    }
}
