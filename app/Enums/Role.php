<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum Role: string implements HasLabel
{
    case Admin = 'admin';
    case Operator = 'operator';
    case Warehouse = 'warehouse';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Yönetici',
            self::Operator => 'Saha operatörü',
            self::Warehouse => 'Depo',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    /**
     * Rolün sahip olduğu yetkiler (CLAUDE.md bölüm 6).
     *
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Admin => Permission::cases(),
            self::Operator => [
                Permission::MotorModelsView,
                Permission::CustomersView,
                Permission::OrdersView,
                Permission::WorkOrdersView,
                Permission::WorkOrdersChangeStatus,
                Permission::PartsView,
            ],
            self::Warehouse => [
                Permission::MotorModelsView,
                Permission::CustomersView,
                Permission::OrdersView,
                Permission::WorkOrdersView,
                Permission::PartsView,
                Permission::PartsManage,
                Permission::StockMove,
                Permission::ReportsStock,
            ],
        };
    }
}
