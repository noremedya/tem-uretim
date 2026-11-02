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
    // "Motor oluştur" ekranı: bileşen seçerek yeni motor modeli ve reçetesi (tüm roller). Düzenleme MotorModelsManage'de.
    case MotorModelsBuild = 'motor_models.build';

    case CustomersView = 'customers.view';
    case CustomersManage = 'customers.manage';
    case OrdersView = 'orders.view';
    case OrdersManage = 'orders.manage';

    // Görüntüleme; operatör için "yalnızca kendine atananlar" kısıtı policy'de uygulanır.
    case WorkOrdersView = 'work_orders.view';
    // Tüm iş emirlerini görme (tüm roller iş emri görürse). Yoksa yalnızca kendine atananlar görünür.
    case WorkOrdersViewAll = 'work_orders.view_all';
    case WorkOrdersManage = 'work_orders.manage';
    // Durum değiştirme; "yalnızca kendine atananlar" kısıtı policy'de uygulanır.
    case WorkOrdersChangeStatus = 'work_orders.change_status';
    case WorkOrdersChangeStatusAny = 'work_orders.change_status_any';

    // Üretilen motorları görüntüleme (motor modeli görmeden de: operatör, depo).
    case MotorUnitsView = 'motor_units.view';
    // Üretilen motorların sevki (yönetici, operatör, depo).
    case MotorUnitsShip = 'motor_units.ship';
    case MotorUnitsOpening = 'motor_units.opening';
    case MotorUnitsReturn = 'motor_units.return';

    case PartsView = 'parts.view';
    case PartsManage = 'parts.manage';
    case StockMove = 'stock.move';
    case StockMovementsView = 'stock_movements.view';

    // Ön muhasebe (Aşama 5).
    case PricesManage = 'prices.manage';
    case ExchangeRatesManage = 'exchange_rates.manage';
    case InvoicesManage = 'invoices.manage';
    // Tahsilat/ödeme, kasa/banka, çek/senet, cari ekstre.
    case FinanceManage = 'finance.manage';
    // Alış faturalarını salt okunur görme (depo).
    case InvoicesViewPurchase = 'invoices.view_purchase';

    case ReportsProduction = 'reports.production';
    case ReportsStock = 'reports.stock';

    case AuditLogView = 'audit_log.view';

    public function label(): string
    {
        return match ($this) {
            self::UsersManage => 'Kullanıcı yönetimi',
            self::MotorModelsView => 'Motor modeli ve reçete görüntüleme',
            self::MotorModelsManage => 'Motor modeli ve reçete yönetimi',
            self::MotorModelsBuild => 'Bileşen seçerek motor oluşturma',
            self::CustomersView => 'Müşteri görüntüleme',
            self::CustomersManage => 'Müşteri yönetimi',
            self::OrdersView => 'Sipariş görüntüleme',
            self::OrdersManage => 'Sipariş yönetimi',
            self::WorkOrdersView => 'İş emri görüntüleme',
            self::WorkOrdersViewAll => 'Tüm iş emirlerini görüntüleme',
            self::WorkOrdersManage => 'İş emri oluşturma/atama',
            self::WorkOrdersChangeStatus => 'İş emri durum değiştirme (kendine atananlar)',
            self::WorkOrdersChangeStatusAny => 'İş emri durum değiştirme (tümü)',
            self::MotorUnitsView => 'Üretilen motorları görüntüleme',
            self::MotorUnitsShip => 'Motor sevki',
            self::MotorUnitsOpening => 'Açılış motoru girme',
            self::MotorUnitsReturn => 'Motor iadesi alma',
            self::PartsView => 'Stok kartı görüntüleme',
            self::PartsManage => 'Stok kartı yönetimi',
            self::StockMove => 'Stok giriş/çıkış/sayım',
            self::StockMovementsView => 'Stok hareketleri görüntüleme',
            self::PricesManage => 'Fiyat yönetimi',
            self::ExchangeRatesManage => 'Döviz kuru yönetimi',
            self::InvoicesManage => 'Fatura yönetimi',
            self::FinanceManage => 'Tahsilat, ödeme, kasa ve banka',
            self::InvoicesViewPurchase => 'Alış faturalarını görüntüleme',
            self::ReportsProduction => 'Üretim raporları',
            self::ReportsStock => 'Stok raporları',
            self::AuditLogView => 'Denetim izi',
        };
    }
}
