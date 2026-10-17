<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ExchangeRate;
use App\Models\User;

/** Döviz kurları: yönetici ve muhasebe görür, elle girer ve düzeltir. Kur silinmez. */
class ExchangeRatePolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can(Permission::ExchangeRatesManage);
    }

    public function view(User $actor, ExchangeRate $rate): bool
    {
        return $actor->can(Permission::ExchangeRatesManage);
    }

    public function create(User $actor): bool
    {
        return $actor->can(Permission::ExchangeRatesManage);
    }

    public function update(User $actor, ExchangeRate $rate): bool
    {
        return $actor->can(Permission::ExchangeRatesManage);
    }

    public function delete(User $actor, ExchangeRate $rate): bool
    {
        return false;
    }

    public function deleteAny(User $actor): bool
    {
        return false;
    }
}
