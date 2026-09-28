<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\StockMovement;
use App\Models\User;

/**
 * Stok hareketleri salt okunurdur; yalnızca StockService oluşturur.
 */
class StockMovementPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can(Permission::StockMovementsView);
    }

    public function view(User $actor, StockMovement $movement): bool
    {
        return $actor->can(Permission::StockMovementsView);
    }

    /** Ters kayıt oluşturma (kayıt uygunluğu StockService'te ayrıca kontrol edilir). */
    public function correct(User $actor, StockMovement $movement): bool
    {
        return $actor->can(Permission::StockMove) && $movement->isCorrectableType();
    }

    public function create(User $actor): bool
    {
        return false;
    }

    public function update(User $actor, StockMovement $movement): bool
    {
        return false;
    }

    public function delete(User $actor, StockMovement $movement): bool
    {
        return false;
    }

    public function deleteAny(User $actor): bool
    {
        return false;
    }
}
