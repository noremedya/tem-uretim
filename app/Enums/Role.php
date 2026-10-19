<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum Role: string implements HasLabel
{
    case Admin = 'admin';
    case Operator = 'operator';
    case Warehouse = 'warehouse';
    case Accounting = 'accounting';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Yönetici',
            self::Operator => 'Saha operatörü',
            self::Warehouse => 'Depo',
            self::Accounting => 'Muhasebe',
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
            // Operatör ve depo cari hesapları ve müşteri adlarını görmez (CLAUDE.md bölüm 6).
            self::Operator => [
                Permission::MotorModelsView,
                Permission::OrdersView,
                Permission::WorkOrdersView,
                Permission::WorkOrdersChangeStatus,
                Permission::PartsView,
            ],
            self::Warehouse => [
                Permission::MotorModelsView,
                Permission::OrdersView,
                Permission::WorkOrdersView,
                Permission::WorkOrdersViewAll,
                Permission::MotorUnitsShip,
                Permission::PartsView,
                Permission::PartsManage,
                Permission::StockMove,
                Permission::StockMovementsView,
                Permission::ReportsStock,
            ],
            self::Accounting => [
                Permission::MotorModelsView,
                Permission::CustomersView,
                Permission::CustomersManage,
                Permission::OrdersView,
                Permission::PartsView,
                Permission::PricesManage,
                Permission::ExchangeRatesManage,
                Permission::InvoicesManage,
                Permission::FinanceManage,
            ],
        };
    }
}
