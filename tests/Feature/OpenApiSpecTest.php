<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The published spec has to describe the API that exists.
 *
 * A spec that lies is worse than no spec: a client generated from it fails in
 * ways that look like server bugs. So this asserts the generated description
 * still covers every v1 route, which is the way it would rot — somebody adds
 * an endpoint and never regenerates.
 */
class OpenApiSpecTest extends TestCase
{
    protected string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = base_path('storage/framework/testing/openapi.json');
        Artisan::call('opes:export-openapi', ['--out' => 'storage/framework/testing/openapi.json']);
    }

    protected function spec(): array
    {
        return json_decode(file_get_contents($this->path), true);
    }

    public function test_the_spec_is_valid_json_and_declares_openapi_3(): void
    {
        $spec = $this->spec();

        $this->assertSame('3.1.0', $spec['openapi']);
        $this->assertNotEmpty($spec['paths']);
        $this->assertArrayHasKey('bearerAuth', $spec['components']['securitySchemes']);
    }

    public function test_every_v1_route_appears_in_the_spec(): void
    {
        $spec = $this->spec();
        $missing = [];

        foreach (Route::getRoutes() as $route) {
            if (! Str::startsWith($route->uri(), 'api/v1/')) {
                continue;
            }

            $path = '/'.$route->uri();

            foreach ($route->methods() as $method) {
                if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }

                if (! isset($spec['paths'][$path][strtolower($method)])) {
                    $missing[] = $method.' '.$path;
                }
            }
        }

        $this->assertSame([], $missing, 'Regenerate the spec: php artisan opes:export-openapi');
    }

    /** The scope an endpoint needs is part of its contract, not a surprise. */
    public function test_scoped_endpoints_declare_their_scope(): void
    {
        $spec = $this->spec();

        $this->assertSame(
            [['bearerAuth' => ['money']]],
            $spec['paths']['/api/v1/payments']['post']['security']
        );

        $this->assertSame(
            [['bearerAuth' => ['people']]],
            $spec['paths']['/api/v1/employees']['get']['security']
        );
    }

    /** Minting a token is the one call that cannot need one. */
    public function test_the_token_endpoint_is_declared_public(): void
    {
        $this->assertSame([], $this->spec()['paths']['/api/v1/tokens']['post']['security']);
    }

    public function test_money_endpoints_advertise_the_idempotency_header(): void
    {
        $parameters = $this->spec()['paths']['/api/v1/payments']['post']['parameters'];

        $this->assertTrue(
            collect($parameters)->contains(fn ($p) => ($p['$ref'] ?? '') === '#/components/parameters/idempotencyKey'),
            'A retryable money endpoint must document Idempotency-Key.'
        );
    }

    protected function tearDown(): void
    {
        @unlink($this->path);

        parent::tearDown();
    }
}
