<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Content Security Policy.
 *
 * What this policy honestly achieves, and what it does not:
 *
 *  - It stops script being loaded from anywhere but this origin, stops the page
 *    being framed, stops `<base>` and form-action hijacking, and stops data
 *    being posted or beaconed to a third-party host. Those are the directives
 *    that turn a stored-XSS foothold into a dead end, and they are all fully
 *    enforced here.
 *
 *  - It cannot drop `'unsafe-eval'`. Alpine evaluates the expressions inside
 *    `x-data` and `x-show` with `new Function()`, and Livewire 3 ships Alpine.
 *    The CSP-safe Alpine build forbids expressions entirely, which would mean
 *    rewriting every interactive view in the product. Pretending otherwise by
 *    omitting the directive would simply break every page.
 *
 *  - Inline styles stay allowed. The layout uses `style` attributes for iOS
 *    safe-area insets and the print views compose page geometry inline; both
 *    are static, author-written values rather than anything user-supplied.
 *
 * Inline *scripts* do carry a nonce, so an injected `<script>` still cannot run.
 *
 * ── Why hashes as well as the nonce ──────────────────────────────────────
 *
 * A nonce is per response, which is exactly what makes it worth having and
 * exactly what breaks under `wire:navigate`. Livewire fetches the next page
 * over XHR and re-injects its head scripts into the *current* document, whose
 * CSP header carries the *previous* nonce — so every single navigation logged
 * two refusals for scripts the application itself had written. Nothing broke
 * (the anti-flash script had already done its work, and the prefetch helper is
 * an optimisation), but a console with two known violations on every page
 * transition is a console in which a real injection attempt goes unnoticed.
 *
 * The fix is the one the browser suggests in the error itself: pin the exact
 * bytes of those scripts with a `sha256-…` source. A hash is content-addressed,
 * so it is stable across responses and survives the re-injection, while still
 * permitting *only* those two scripts and nothing else — it is strictly
 * narrower than the alternative of `'unsafe-inline'`, and does not weaken the
 * nonce for anything else.
 */
class Csp
{
    /**
     * The inline scripts the layouts ship, hashed.
     *
     * Kept beside the CSP rather than derived from the rendered page, because a
     * policy computed from the response it is protecting is not a policy. If
     * one of these scripts is edited its hash changes and the browser refuses
     * it on the next navigation — which is the failure being loud rather than
     * silent, and `CspTest` asserts the hashes match what the layouts contain.
     *
     * @var array<int, string>
     */
    public const INLINE_SCRIPT_HASHES = [
        // resources/views/partials/theme-boot.blade.php — the anti-flash theme
        // script, which must run before first paint. Shared by all three
        // layouts — app, public and admin — each of which had its own
        // slightly different copy, so one hash rather than three to keep in step.
        'sha256-kYI4+tPqbrc7RzVZLdZKTce93/u6cI3lYiobsd8nYz0=',
        // Laravel's Vite asset prefetch helper, emitted by @vite.
        'sha256-f2dPU+s9MgICWc5L5shn/fTAEcFJJUc9Y9hxHCv/hqA=',
    ];

    protected ?string $nonce = null;

    public function nonce(): string
    {
        return $this->nonce ??= Str::random(24);
    }

    /** The nonce attribute, ready to drop into a tag. */
    public function attribute(): string
    {
        return 'nonce="'.e($this->nonce()).'"';
    }

    /**
     * @param  bool  $embeddable  true only for the /embed variants of public
     *                            forms and event pages, which exist to be
     *                            iframed into other people's websites — the
     *                            Google Forms model. Everything else keeps
     *                            frame-ancestors 'none': no other page in a
     *                            financial product has any business inside
     *                            someone else's frame.
     * @param  bool  $selfEmbeddable  true for pages this app frames into its
     *                                own UI (the stationery print view backs
     *                                the live card previews). 'self' only —
     *                                other origins still cannot frame them.
     */
    public function header(bool $embeddable = false, bool $selfEmbeddable = false): string
    {
        $nonce = "'nonce-".$this->nonce()."'";

        return collect([
            "default-src 'self'",
            // 'unsafe-eval' is required by Alpine, and the hashes cover the two
            // inline scripts Livewire re-injects on navigate — see the docblock.
            "script-src 'self' {$nonce} 'unsafe-eval' ".
                collect(self::INLINE_SCRIPT_HASHES)->map(fn (string $h) => "'{$h}'")->implode(' '),
            "style-src 'self' 'unsafe-inline'",
            // data: covers the inlined QR codes and logo previews; blob: covers
            // the camera stream the scanner reads from.
            "img-src 'self' data: blob:",
            "font-src 'self' data:",
            "media-src 'self' blob:",
            // The sync API and Livewire both talk to this origin and nowhere
            // else, so exfiltration over fetch has nowhere to go.
            "connect-src 'self'",
            "worker-src 'self' blob:",
            "manifest-src 'self'",
            "form-action 'self'",
            $embeddable ? 'frame-ancestors *' : ($selfEmbeddable ? "frame-ancestors 'self'" : "frame-ancestors 'none'"),
            "base-uri 'self'",
            "object-src 'none'",
        ])->implode('; ');
    }
}
