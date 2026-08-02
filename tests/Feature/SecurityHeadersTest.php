<?php

namespace Tests\Feature;

use App\Support\Csp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_policy_is_enforced_on_public_pages(): void
    {
        $header = $this->get('/login')->assertOk()->headers->get('Content-Security-Policy');

        $this->assertNotNull($header, 'No Content-Security-Policy header was sent.');
    }

    public function test_the_directives_that_actually_stop_an_injected_script(): void
    {
        $header = $this->get('/login')->headers->get('Content-Security-Policy');

        // These are the ones worth asserting: they are what turns a stored-XSS
        // foothold into a dead end, and they are all fully enforceable here.
        $this->assertStringContainsString("default-src 'self'", $header);
        $this->assertStringContainsString("object-src 'none'", $header);
        $this->assertStringContainsString("base-uri 'self'", $header);
        $this->assertStringContainsString("form-action 'self'", $header);
        $this->assertStringContainsString("frame-ancestors 'none'", $header);
        // Nowhere to exfiltrate to: fetch, XHR and beacons are same-origin only.
        $this->assertStringContainsString("connect-src 'self'", $header);
    }

    public function test_inline_scripts_are_nonced_rather_than_blanket_allowed(): void
    {
        $response = $this->get('/login')->assertOk();

        $header = $response->headers->get('Content-Security-Policy');

        preg_match("/'nonce-([A-Za-z0-9]+)'/", $header, $matches);

        $this->assertNotEmpty($matches, 'script-src carries no nonce.');

        // 'unsafe-inline' would make the nonce pointless — browsers ignore the
        // nonce entirely when it is present alongside.
        $this->assertStringNotContainsString("'unsafe-inline'", explode(';', $header)[1]);

        // Every inline script the page ships must carry that nonce, or it is
        // both broken and evidence the policy was never really tested.
        $html = $response->getContent();

        $this->assertStringContainsString('<script nonce="'.$matches[1].'"', $html);
        $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*(nonce=|src=))[^>]*>/', $html);
    }

    /**
     * The hashes must match the bytes the layouts actually ship.
     *
     * They exist because `wire:navigate` re-injects the head scripts of the
     * fetched page into the current document, whose header still carries the
     * previous nonce — so every navigation logged two refusals for scripts this
     * application wrote itself. A content hash survives that; a nonce cannot.
     *
     * The risk of pinning bytes is that somebody edits one of those scripts and
     * the browser starts refusing it on navigate, silently, in production. This
     * is what makes that loud: change the script, this fails.
     */
    public function test_the_pinned_inline_script_hashes_match_what_the_layouts_ship(): void
    {
        $sources = array_merge(
            glob(resource_path('views/components/layouts/*.blade.php')),
            glob(resource_path('views/partials/*.blade.php')),
        );

        $found = [];

        foreach ($sources as $file) {
            preg_match_all('/<script[^>]*>(.*?)<\/script>/s', file_get_contents($file), $matches);

            foreach ($matches[1] as $body) {
                if (trim($body) === '') {
                    continue;
                }
                $found[] = 'sha256-'.base64_encode(hash('sha256', $body, true));
            }
        }

        foreach ($found as $hash) {
            $this->assertContains($hash, Csp::INLINE_SCRIPT_HASHES, sprintf(
                "An inline script in a layout is not pinned in Csp::INLINE_SCRIPT_HASHES.\n".
                "wire:navigate will re-inject it with a stale nonce and the browser will refuse it.\n".
                'Add: %s',
                $hash
            ));
        }
    }

    /** And they must reach the header, or pinning them achieved nothing. */
    public function test_the_hashes_are_in_the_policy(): void
    {
        $header = $this->get('/login')->headers->get('Content-Security-Policy');

        foreach (Csp::INLINE_SCRIPT_HASHES as $hash) {
            $this->assertStringContainsString("'".$hash."'", $header);
        }
    }

    public function test_a_nonce_is_not_reused_between_requests(): void
    {
        // A predictable or shared nonce is the same as no nonce at all.
        $first = app(Csp::class)->nonce();

        $this->refreshApplication();

        $this->assertNotSame($first, app(Csp::class)->nonce());
    }

    public function test_the_baseline_headers_are_present(): void
    {
        $response = $this->get('/login');

        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('strict-origin-when-cross-origin', $response->headers->get('Referrer-Policy'));
        $this->assertStringContainsString('microphone=()', $response->headers->get('Permissions-Policy'));
    }

    public function test_report_only_mode_does_not_block(): void
    {
        // The safe way to tighten the policy on a live deployment: violations
        // are reported, nothing is blocked.
        config()->set('security.csp.report_only', true);

        $response = $this->get('/login');

        $this->assertNull($response->headers->get('Content-Security-Policy'));
        $this->assertNotNull($response->headers->get('Content-Security-Policy-Report-Only'));
    }
}
