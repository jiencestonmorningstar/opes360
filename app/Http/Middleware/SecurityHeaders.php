<?php

namespace App\Http\Middleware;

use App\Support\Csp;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline security headers, including the Content Security Policy.
 *
 * The policy itself — and an honest account of what it can and cannot enforce
 * given that Livewire ships Alpine — lives in App\Support\Csp.
 *
 * It can be run in report-only mode (`CSP_REPORT_ONLY=true`) to watch for
 * breakage on a live deployment before enforcing, and disabled outright
 * (`CSP_ENABLED=false`) if a specific deployment needs it off.
 */
class SecurityHeaders
{
    public function __construct(protected Csp $csp) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // The /embed variants of public forms and event pages exist to be
        // iframed into other websites, so they alone skip the anti-framing
        // headers. Everything else stays locked to this origin.
        $embeddable = $request->routeIs('form.embed', 'form.embed.submit', 'event.embed');

        // The stationery print view is framed by this app's own stationery
        // page as the live preview; 'self' keeps every other origin out.
        $selfEmbeddable = $request->routeIs('stationery.print');

        if (! $embeddable) {
            // Superseded by the policy's frame-ancestors, kept for browsers
            // that predate CSP level 2.
            $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        }

        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // The app asks for the camera (QR scanning) and nothing else.
        $response->headers->set(
            'Permissions-Policy',
            'camera=(self), microphone=(), geolocation=(self), payment=(), usb=(self)'
        );

        if (config('security.csp.enabled', true)) {
            $response->headers->set(
                config('security.csp.report_only')
                    ? 'Content-Security-Policy-Report-Only'
                    : 'Content-Security-Policy',
                $this->csp->header(embeddable: $embeddable, selfEmbeddable: $selfEmbeddable),
            );
        }

        // HSTS only over HTTPS: sending it over plain HTTP is meaningless, and in
        // local development it would pin the browser to a scheme that is not served.
        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
