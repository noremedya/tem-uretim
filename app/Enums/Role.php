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
            // Operatör ve depo yalnızca stokları, iş emirlerini ve üretilen motorları görür; müdahaleleri:
            // operatör kendine atanan iş emrini başlatır/tamamlar ve sevk eder, depo stok işlemi ve sevk yapar.
            // Motor modeli, sipariş, parti, cari, fatura, rapor göremezler (CLAUDE.md bölüm 6).
            self::Operator => [
                Permission::WorkOrdersView,
                Permission::WorkOrdersViewAll,
                Permission::WorkOrdersChangeStatus,
                Permission::MotorUnitsView,
                Permission::MotorUnitsShip,
                Permission::PartsView,
                Permission::StockMovementsView,
            ],
            self::Warehouse => [
                Permission::WorkOrdersView,
                Permission::WorkOrdersViewAll,
                Permission::MotorUnitsView,
                Permission::MotorUnitsShip,
                Permission::PartsView,
                Permission::StockMove,
                Permission::StockMovementsView,
            ],
            self::Accounting => [
                Permission::MotorModelsView,
                Permission::MotorUnitsView,
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
