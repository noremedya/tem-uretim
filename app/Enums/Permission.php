<?php

namespace App\Enums;

/**
 * Uygulama yetkileri. Roller ile eşleşmesi Role::permissions() içindedir;
 * RolesAndPermissionsSeeder bu enum'dan üretir.
 */
enum Permission: string
{
    case UsersManage = 'users.manage';

    case MotorModelsView = 'motor_models.view';
    case MotorModelsManage = 'motor_models.manage';

    case CustomersView = 'customers.view';
    case CustomersManage = 'customers.manage';
    case OrdersView = 'orders.view';
    case OrdersManage = 'orders.manage';

    case WorkOrdersManage = 'work_orders.manage';
    // Durum değiştirme; "yalnızca kendine atananlar" kısıtı policy'de uygulanır.
    case WorkOrdersChangeStatus = 'work_orders.change_status';
    case WorkOrdersChangeStatusAny = 'work_orders.change_status_any';

    case PartsView = 'parts.view';
    case PartsManage = 'parts.manage';
    case StockMove = 'stock.move';

    case ReportsProduction = 'reports.production';
    case ReportsStock = 'reports.stock';

    case AuditLogView = 'audit_log.view';

    public function label(): string
    {
        return match ($this) {
            self::UsersManage => 'Kullanıcı yönetimi',
            self::MotorModelsView => 'Motor modeli ve reçete görüntüleme',
            self::MotorModelsManage => 'Motor modeli ve reçete yönetimi',
            self::CustomersView => 'Müşteri görüntüleme',
            self::CustomersManage => 'Müşteri yönetimi',
            self::OrdersView => 'Sipariş görüntüleme',
            self::OrdersManage => 'Sipariş yönetimi',
            self::WorkOrdersManage => 'İş emri oluşturma/atama',
            self::WorkOrdersChangeStatus => 'İş emri durum değiştirme (kendine atananlar)',
            self::WorkOrdersChangeStatusAny => 'İş emri durum değiştirme (tümü)',
            self::PartsView => 'Stok kartı görüntüleme',
            self::PartsManage => 'Stok kartı yönetimi',
            self::StockMove => 'Stok giriş/çıkış/sayım',
            self::ReportsProduction => 'Üretim raporları',
            self::ReportsStock => 'Stok raporları',
            self::AuditLogView => 'Denetim izi',
        };
    }
}
