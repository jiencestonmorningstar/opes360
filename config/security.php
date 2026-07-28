<?php

return [

    /*
     * Content Security Policy. See App\Support\Csp for what the policy actually
     * enforces and why one directive cannot be tightened further.
     *
     * Report-only is the safe way to introduce or tighten the policy on a live
     * deployment: violations are reported by the browser without anything being
     * blocked, so a missed inline script shows up as a console message rather
     * than a blank page.
     */
    'csp' => [
        'enabled' => (bool) env('CSP_ENABLED', true),
        'report_only' => (bool) env('CSP_REPORT_ONLY', false),
    ],

];
