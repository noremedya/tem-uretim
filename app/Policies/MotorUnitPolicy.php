<?php

namespace App\Policies;

use App\Enums\MotorUnitStatus;
use App\Enums\Permission;
use App\Models\MotorUnit;
use App\Models\User;

/**
 * Üretilen motorlar: tüm roller görüntüler; yönetici, operatör ve depo sevk eder (CLAUDE.md bölüm 6).
 * Motor kaydı açılış akışı dışında elle oluşturulmaz, değiştirilmez, silinmez; iadeyi yönetici ve depo alır.
 */
class MotorUnitPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can(Permission::MotorUnitsView);
    }

    public function view(User $actor, MotorUnit $unit): bool
    {
        return $actor->can(Permission::MotorUnitsView);
    }

    public function ship(User $actor, MotorUnit $unit): bool
    {
        return $actor->can(Permission::MotorUnitsShip) && $unit->status === MotorUnitStatus::InStock;
    }

    public function shipAny(User $actor): bool
    {
        return $actor->can(Permission::MotorUnitsShip);
    }

    public function registerOpening(User $actor): bool
    {
        return $actor->can(Permission::MotorUnitsOpening);
    }

    public function cancelOpening(User $actor, MotorUnit $unit): bool
    {
        return $actor->can(Permission::MotorUnitsOpening) && $unit->isOpening() && $unit->status === MotorUnitStatus::InStock;
    }

    public function receiveReturn(User $actor, MotorUnit $unit): bool
    {
        return $actor->can(Permission::MotorUnitsReturn) && $unit->status === MotorUnitStatus::Shipped;
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
