<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Price;
use App\Models\User;

/** Fiyatlar: yönetici ve muhasebe. Yalnızca ileri tarihli fiyat düzenlenir veya silinir. */
class PricePolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can(Permission::PricesManage);
    }

    public function view(User $actor, Price $price): bool
    {
        return $actor->can(Permission::PricesManage);
    }

    public function create(User $actor): bool
    {
        return $actor->can(Permission::PricesManage);
    }

    public function update(User $actor, Price $price): bool
    {
        return $actor->can(Permission::PricesManage) && $price->isFuture();
    }

    public function delete(User $actor, Price $price): bool
    {
        return $actor->can(Permission::PricesManage) && $price->isFuture();
    }

    public function deleteAny(User $actor): bool
    {
        return false;
    }
}
