<?php

namespace App\Policies;

use App\Enums\MotorUnitStatus;
use App\Enums\Permission;
use App\Models\MotorUnit;
use App\Models\User;

/**
 * Üretilen motorlar: herkes görüntüler, yönetici ve depo sevk eder (CLAUDE.md bölüm 6).
 * Motor kayıtları elle oluşturulmaz, değiştirilmez, silinmez.
 */
class MotorUnitPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can(Permission::MotorModelsView);
    }

    public function view(User $actor, MotorUnit $unit): bool
    {
        return $actor->can(Permission::MotorModelsView);
    }

    public function ship(User $actor, MotorUnit $unit): bool
    {
        return $actor->can(Permission::MotorUnitsShip) && $unit->status === MotorUnitStatus::InStock;
    }

    public function shipAny(User $actor): bool
    {
        return $actor->can(Permission::MotorUnitsShip);
    }

    public function create(User $actor): bool
    {
        return false;
    }

    public function update(User $actor, MotorUnit $unit): bool
    {
        return false;
    }

    public function delete(User $actor, MotorUnit $unit): bool
    {
        return false;
    }

    public function deleteAny(User $actor): bool
    {
        return false;
    }
}
