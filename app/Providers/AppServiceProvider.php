<?php

namespace App\Providers;

use App\Models\Artisan;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Document;
use App\Models\Item;
use App\Models\Payment;
use App\Models\Receipt;
use App\Models\User;
use App\Observers\AuditObserver;
use App\Support\Csp;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CurrentCompany::class);
        // One nonce per request, shared by the header and every inline script.
        $this->app->singleton(Csp::class);
    }

    public function boot(): void
    {
        // Fail loudly in development on lazy loads and bad attribute assignment,
        // rather than shipping N+1 queries to a phone on a slow connection.
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        Vite::prefetch(concurrency: 3);

        /*
         * Content Security Policy plumbing. `@cspNonce` marks the handful of
         * author-written inline scripts as trusted; Livewire and Vite are told
         * the same nonce so their injected tags carry it too. Anything injected
         * by an attacker has no nonce and therefore does not run.
         */
        Blade::directive('cspNonce', fn () => "<?php echo app(\App\Support\Csp::class)->attribute(); ?>");

        // Resolved here rather than lazily because both APIs take a value, not
        // a callback. boot() runs once per request under PHP-FPM, so the nonce
        // is still per-request; a long-lived worker (Octane) would need this
        // moved into a request-scoped listener.
        $nonce = app(Csp::class)->nonce();

        Livewire::useScriptTagAttributes(['nonce' => $nonce]);
        Vite::useCspNonce($nonce);

        Date::use(Carbon::class);

        // Token-styled pagination; the framework default hardcodes grays that
        // break in dark mode.
        Paginator::defaultView('pagination.opes');
        Paginator::defaultSimpleView('pagination.opes');

        /*
         * Audit trail. Deliberately a fixed list rather than every model: these
         * are the records where "who changed this, and from what" has to be
         * answerable. Logging line items or stock movements as well would bury
         * that signal in churn, and both are already immutable or append-only.
         */
        foreach ([Company::class, User::class, Contact::class, Item::class,
            Document::class, Payment::class, Receipt::class, Artisan::class] as $model) {
            $model::observe(AuditObserver::class);
        }
    }
}
