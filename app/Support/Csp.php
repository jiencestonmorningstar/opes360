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
 */
class Csp
{
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
            // 'unsafe-eval' is required by Alpine — see the class docblock.
            "script-src 'self' {$nonce} 'unsafe-eval'",
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
