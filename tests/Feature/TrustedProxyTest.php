<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every realistic deployment of this app sits behind something that terminates
 * TLS — nginx, a load balancer, cPanel's proxy. If the forwarded headers are not
 * trusted, Laravel decides the request is plain http and then builds every URL
 * that way: the service worker will not register without a secure context, and
 * printed QR codes point at a scheme the site does not answer on.
 *
 * The failure is silent — pages still render — which is why it is worth a test.
 */
class TrustedProxyTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_forwarded_https_request_is_treated_as_secure(): void
    {
        $response = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeaders(['X-Forwarded-Proto' => 'https'])
            ->get('/login');

        $response->assertOk();

        // HSTS is only sent over a secure request, so its presence is proof the
        // forwarded scheme was believed.
        $this->assertNotNull(
            $response->headers->get('Strict-Transport-Security'),
            'The forwarded https scheme was not trusted, so the request was treated as plain http.',
        );
    }

    public function test_generated_urls_follow_the_forwarded_scheme(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'opes360.test'])
            ->get('/login')
            ->assertOk()
            // A QR code built with http:// resolves to nothing on an https site.
            ->assertSee('https://', false);
    }
}
