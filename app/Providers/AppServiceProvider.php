<?php

namespace App\Providers;

use App\Models\Part;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
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
        ]);

        // N+1 ve yanlışlıkla atılan alanları geliştirmede erken yakala.
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());
    }
}
