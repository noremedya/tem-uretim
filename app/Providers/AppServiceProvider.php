<?php

namespace App\Providers;

use App\Models\BomItem;
use App\Models\Customer;
use App\Models\ExchangeRate;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\MotorModel;
use App\Models\MotorUnit;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Part;
use App\Models\Price;
use App\Models\ProductionBatch;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Models\WorkOrder;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Number;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Number::useLocale(config('app.locale'));
        Carbon::setLocale(config('app.locale'));

        // Morph tiplerinde sınıf adı yerine sabit takma ad (activity_log, model_has_roles, notifications...).
        Relation::enforceMorphMap([
            'user' => User::class,
            'unit' => Unit::class,
            'part' => Part::class,
            'stock_movement' => StockMovement::class,
            'motor_model' => MotorModel::class,
            'bom_item' => BomItem::class,
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
        ]);

        // N+1 ve yanlışlıkla atılan alanları geliştirmede erken yakala.
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());
    }
}
