<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Rules\PublicHttpsUrl;
use App\Support\CurrentCompany;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A webhook endpoint is a URL a tenant tells our server to POST to, which is
 * server-side request forgery with a management screen unless it is guarded.
 *
 * The two things asserted here are the ones that turn this feature from a
 * convenience into a liability: that a tenant cannot aim it at our own
 * network, and that the signing secret is not sitting in the table in the
 * clear.
 */
class WebhookSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->owner = User::factory()->create();
        $this->company = Company::create([
            'slug' => 'acme-'.Str::lower(Str::random(4)),
            'name' => 'Acme Sarl',
            'owner_id' => $this->owner->id,
            'currency' => 'XAF',
            'plan' => 'business',
            'account_type' => 'active',
        ]);

        $this->joinCompany($this->company, $this->owner, Role::OWNER);
        $this->owner->forceFill(['current_company_id' => $this->company->id])->save();

        app(CurrentCompany::class)->set($this->company);

        Sanctum::actingAs($this->owner, ['*']);
    }

    // ── The rule itself ──────────────────────────────────────────────────

    /**
     * 169.254.169.254 is the cloud metadata address. A server that will POST
     * there on request hands over its own instance credentials.
     */
    public function test_the_cloud_metadata_address_is_refused(): void
    {
        $this->assertFalse(PublicHttpsUrl::isAllowed('https://169.254.169.254/latest/meta-data/'));
    }

    public function test_loopback_and_private_ranges_are_refused(): void
    {
        foreach ([
            'https://127.0.0.1/hook',
            'https://localhost/hook',
            'https://10.0.0.5/admin',
            'https://192.168.1.1/',
            'https://172.16.0.1/',
            'https://[::1]/hook',
        ] as $url) {
            $this->assertFalse(PublicHttpsUrl::isAllowed($url), $url.' should be refused.');
        }
    }

    /** Plain http would put the payload and its signature on the wire. */
    public function test_http_is_refused(): void
    {
        $this->assertFalse(PublicHttpsUrl::isAllowed('http://example.com/hook'));
    }

    public function test_a_public_https_address_is_allowed(): void
    {
        $this->assertTrue(PublicHttpsUrl::isAllowed('https://example.com/hook'));
    }

    /**
     * A name with no DNS answer is not refused: there is nothing behind it to
     * reach, and refusing it would block a customer whose DNS has not
     * propagated — and, worse, would permanently kill a delivery on a
     * momentary resolver blip instead of letting it retry.
     */
    public function test_an_unresolvable_host_is_left_to_fail_naturally(): void
    {
        $this->assertTrue(PublicHttpsUrl::isAllowed('https://nothing-here.invalid/hook'));
    }

    /**
     * The refusal must not say which internal range answered — that would make
     * the endpoint form a network scanner in its own right.
     */
    public function test_the_refusal_does_not_describe_the_network(): void
    {
        PublicHttpsUrl::isAllowed('https://10.0.0.5/admin', $reason);

        $this->assertStringNotContainsString('10.0.0', (string) $reason);
        $this->assertStringNotContainsString('private', Str::lower((string) $reason));
    }

    // ── Through the API ──────────────────────────────────────────────────

    public function test_the_api_refuses_an_internal_address(): void
    {
        $this->postJson('/api/v1/webhooks', [
            'url' => 'https://169.254.169.254/latest/meta-data/',
            'events' => ['payment.recorded'],
        ])->assertStatus(422)->assertJsonValidationErrors('url');

        $this->assertSame(0, WebhookEndpoint::count());
    }

    public function test_the_api_accepts_a_public_address(): void
    {
        $this->postJson('/api/v1/webhooks', [
            'url' => 'https://example.com/hook',
            'events' => ['payment.recorded'],
        ])->assertCreated();

        $this->assertSame(1, WebhookEndpoint::count());
    }

    // ── The secret ───────────────────────────────────────────────────────

    /**
     * A dump that leaks every tenant's signing secret lets anybody forge
     * deliveries to their endpoints, which is worse than leaking the data the
     * deliveries carry.
     */
    public function test_the_signing_secret_is_encrypted_at_rest(): void
    {
        $endpoint = WebhookEndpoint::create([
            'company_id' => $this->company->id,
            'url' => 'https://example.com/hook',
            'events' => ['payment.recorded'],
            'secret' => 'whsec_plaintext_value',
            'is_active' => true,
        ]);

        $stored = DB::table('webhook_endpoints')->where('id', $endpoint->id)->value('secret');

        $this->assertNotSame('whsec_plaintext_value', $stored);
        $this->assertStringNotContainsString('plaintext_value', (string) $stored);

        // …and still readable through the model, or nothing could be signed.
        $this->assertSame('whsec_plaintext_value', $endpoint->fresh()->secret);
    }

    public function test_the_secret_is_never_serialised(): void
    {
        $endpoint = WebhookEndpoint::create([
            'company_id' => $this->company->id,
            'url' => 'https://example.com/hook',
            'events' => ['payment.recorded'],
            'secret' => 'whsec_plaintext_value',
            'is_active' => true,
        ]);

        $this->assertArrayNotHasKey('secret', $endpoint->toArray());

        $this->getJson('/api/v1/webhooks')
            ->assertOk()
            ->assertJsonMissing(['secret' => 'whsec_plaintext_value']);
    }
}
