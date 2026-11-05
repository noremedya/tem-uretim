<?php

namespace App\Providers;

use App\Models\AccountTransaction;
use App\Models\BomCoProduct;
use App\Models\BomItem;
use App\Models\BomTemplate;
use App\Models\Brand;
use App\Models\CashAccount;
use App\Models\CashMovement;
use App\Models\Cheque;
use App\Models\Customer;
use App\Models\ExchangeRate;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\MotorDesign;
use App\Models\MotorDesignItem;
use App\Models\MotorModel;
use App\Models\MotorUnit;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Part;
use App\Models\PartSubtype;
use App\Models\Payment;
use App\Models\Price;
use App\Models\ProductionBatch;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WorkOrder;
use App\Policies\ActivityPolicy;
use App\Services\WarehouseService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;
use Illuminate\Support\ServiceProvider;
use Spatie\Activitylog\Models\Activity;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Varsayılan depo eşlemesini istek (ve kuyruk işi) boyunca önbellekte tutar.
        $this->app->scoped(WarehouseService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Paket modeli için policy (App\Models dışında olduğu için otomatik bulunmaz).
        Gate::policy(Activity::class, ActivityPolicy::class);

        Number::useLocale(config('app.locale'));
        Carbon::setLocale(config('app.locale'));

        // Morph tiplerinde sınıf adı yerine sabit takma ad (activity_log, model_has_roles, notifications...).
        Relation::enforceMorphMap([
            'user' => User::class,
            'unit' => Unit::class,
            'brand' => Brand::class,
            'part_subtype' => PartSubtype::class,
            'warehouse' => Warehouse::class,
            'part' => Part::class,
            'stock_movement' => StockMovement::class,
            'motor_model' => MotorModel::class,
            'bom_item' => BomItem::class,
            'bom_co_product' => BomCoProduct::class,
            'bom_template' => BomTemplate::class,
            'motor_design' => MotorDesign::class,
            'motor_design_item' => MotorDesignItem::class,
            'customer' => Customer::class,
            'order' => Order::class,
            'order_item' => OrderItem::class,
            'production_batch' => ProductionBatch::class,
            'work_order' => WorkOrder::class,
            'motor_unit' => MotorUnit::class,
            'exchange_rate' => ExchangeRate::class,
            'price' => Price::class,
            'invoice' => Invoice::class,
            'invoice_line' => InvoiceLine::class,
            'cash_account' => CashAccount::class,
            'cash_movement' => CashMovement::class,
            'account_transaction' => AccountTransaction::class,
            'payment' => Payment::class,
            'cheque' => Cheque::class,
        ]);

        // N+1 ve yanlışlıkla atılan alanları geliştirmede erken yakala.
        // Geliştirici korumaları yalnızca geliştirme ve testte; üretim ve demo sunucusunda kullanıcıya hata göstermez.
        $strict = ! $this->app->environment('production', config('demo.environment'));
        Model::preventLazyLoading($strict);
        Model::preventSilentlyDiscardingAttributes($strict);
    }
}
