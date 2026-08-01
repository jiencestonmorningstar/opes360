<?php

use App\Http\Middleware\EnsurePlatformAdminRole;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetCurrentCompany;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Auth\Middleware\RedirectIfAuthenticated;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Contracts\Session\Middleware\AuthenticatesSessions;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * Every realistic deployment of this app sits behind something that
         * terminates TLS — nginx, a load balancer, cPanel's proxy. Without
         * trusting its forwarded headers Laravel builds http:// URLs for an
         * https site, which breaks the service worker (no secure context) and
         * prints QR codes pointing at a scheme the site does not answer on.
         *
         * The proxy list is configurable rather than fixed: on shared hosting
         * the terminator is on the same host, behind a load balancer it is not.
         */
        // An unauthenticated hit on an admin.* route must land on the admin
        // login, not the business one — the two guards share no session.
        Authenticate::redirectUsing(function ($request) {
            return $request->routeIs('admin.*') ? route('admin.login') : route('login');
        });

        // The mirror case: an admin who is already signed in and revisits
        // /admin/login must land back on /admin, not on the business
        // dashboard route — the framework default has no guard awareness
        // and would otherwise route them to a page their session can't use.
        RedirectIfAuthenticated::redirectUsing(function ($request) {
            return $request->routeIs('admin.*') ? route('admin.dashboard') : route('dashboard');
        });

        $middleware->trustProxies(
            at: env('TRUSTED_PROXIES', '127.0.0.1,::1') === '*'
                ? '*'
                : explode(',', (string) env('TRUSTED_PROXIES', '127.0.0.1,::1')),
        );

        // Runs on every web request so tenant scoping is never opt-in.
        $middleware->web(append: [
            SetCurrentCompany::class,
        ]);

        $middleware->alias([
            'admin.role' => EnsurePlatformAdminRole::class,
        ]);

        /*
         * The embedded form submit runs inside other people's websites, where
         * third-party cookie rules strip our session cookie — so the CSRF
         * token could never match. The check is also pointless there: CSRF
         * protects cookie-authenticated actions, and this endpoint is public,
         * throttled, and validates a capability URL (the share token) instead.
         */
        $middleware->validateCsrfTokens(except: [
            'f/*/embed',
            'webhooks/*',
        ]);

        // Applied globally so public verification and profile pages — the ones a
        // stranger opens from a printed code — are covered too.
        $middleware->append(SecurityHeaders::class);

        /*
         * SetCurrentCompany must run after auth (it reads the user) but BEFORE
         * route model binding — bindings resolve through the tenant scope, and
         * with no company set the scope fails closed, so every scoped binding
         * would 404. Appending to the web group alone puts it after
         * SubstituteBindings; this priority list corrects that.
         */
        $middleware->priority([
            HandlePrecognitiveRequests::class,
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            AuthenticatesRequests::class,
            ThrottleRequests::class,
            ThrottleRequestsWithRedis::class,
            AuthenticatesSessions::class,
            SetCurrentCompany::class,
            SubstituteBindings::class,
            Authorize::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
