<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline security headers.
 *
 * No CSP is set here. A meaningful policy has to account for Livewire's inline
 * bootstrapping and the inline SVG the print and logo views emit; shipping a
 * permissive `unsafe-inline` policy would look like protection while providing
 * none. Adding a real nonce-based CSP is tracked as a pre-launch item in
 * docs/PLAN.md rather than faked here.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // The app asks for the camera (QR scanning) and nothing else.
        $response->headers->set(
            'Permissions-Policy',
            'camera=(self), microphone=(), geolocation=(self), payment=(), usb=(self)'
        );

        // HSTS only over HTTPS: sending it over plain HTTP is meaningless, and in
        // local development it would pin the browser to a scheme that is not served.
        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
