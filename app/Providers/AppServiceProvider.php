<?php

namespace App\Providers;

use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CurrentCompany::class);
    }

    public function boot(): void
    {
        // Fail loudly in development on lazy loads and bad attribute assignment,
        // rather than shipping N+1 queries to a phone on a slow connection.
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        Vite::prefetch(concurrency: 3);

        Date::use(Carbon::class);

        // Token-styled pagination; the framework default hardcodes grays that
        // break in dark mode.
        Paginator::defaultView('pagination.opes');
        Paginator::defaultSimpleView('pagination.opes');
    }
}
