<?php

namespace App\Policies;

use App\Enums\PaymentStatus;
use App\Enums\Permission;
use App\Models\Payment;
use App\Models\User;

/** Ön muhasebe (tahsilat/ödeme, kasa/banka, çek/senet, cari ekstre): yönetici ve muhasebe. */
class PaymentPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can(Permission::FinanceManage);
    }

    public function view(User $actor, Payment $payment): bool
    {
        return $actor->can(Permission::FinanceManage);
    }

    public function create(User $actor): bool
    {
        return $actor->can(Permission::FinanceManage);
    }

    public function update(User $actor, Payment $payment): bool
    {
        return false;
    }

    public function cancel(User $actor, Payment $payment): bool
    {
        return $actor->can(Permission::FinanceManage) && $payment->status === PaymentStatus::Posted;
    }

    public function delete(User $actor, Payment $payment): bool
    {
        return false;
    }

    public function deleteAny(User $actor): bool
    {
        return false;
    }
}
