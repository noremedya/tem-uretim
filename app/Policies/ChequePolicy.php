<?php

namespace App\Policies;

use App\Enums\ChequeStatus;
use App\Enums\Permission;
use App\Models\Cheque;
use App\Models\User;

/** Ön muhasebe (tahsilat/ödeme, kasa/banka, çek/senet, cari ekstre): yönetici ve muhasebe. */
class ChequePolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can(Permission::FinanceManage);
    }

    public function view(User $actor, Cheque $cheque): bool
    {
        return $actor->can(Permission::FinanceManage);
    }

    public function create(User $actor): bool
    {
        // Elle oluşturulmaz: çek/senet tahsilat/ödemeyle, cari hareketi belgelerle oluşur.
        return false;
    }

    public function update(User $actor, Cheque $cheque): bool
    {
        return false;
    }

    /** Portföydeki çek/senet üzerinde işlem (tahsil, karşılıksız, ödendi). */
    public function process(User $actor, Cheque $cheque): bool
    {
        return $actor->can(Permission::FinanceManage) && $cheque->status === ChequeStatus::Portfolio;
    }

    public function delete(User $actor, Cheque $cheque): bool
    {
        return false;
    }

    public function deleteAny(User $actor): bool
    {
        return false;
    }
}
