<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Support\CurrentCompany;
use App\Support\TokenAbilities;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * What a token may do, as distinct from what its user may do.
 *
 * Scopes are a ceiling, never a grant. The two assertions that matter are
 * that a narrow token is genuinely refused what it did not ask for, and that
 * a wide token still cannot exceed its user — a token carrying every scope
 * belonging to a cashier is still a cashier.
 */
class TokenScopeTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->owner = User::factory()->create(['password' => bcrypt('secret-password')]);
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
    }

    // ── Minting ──────────────────────────────────────────────────────────

    public function test_a_token_asked_for_nothing_in_particular_gets_full_access(): void
    {
        $this->postJson('/api/v1/tokens', [
            'email' => $this->owner->email,
            'password' => 'secret-password',
            'device_name' => 'my own script',
        ])->assertCreated()->assertJsonPath('abilities', ['*']);
    }

    public function test_a_token_can_be_minted_with_narrower_scopes(): void
    {
        $this->postJson('/api/v1/tokens', [
            'email' => $this->owner->email,
            'password' => 'secret-password',
            'device_name' => 'reporting dashboard',
            'abilities' => [TokenAbilities::READ],
        ])->assertCreated()->assertJsonPath('abilities', ['read']);
    }

    /** A name that does not exist is a mistake, not a request for everything. */
    public function test_an_unknown_scope_is_refused_rather_than_widened(): void
    {
        $this->postJson('/api/v1/tokens', [
            'email' => $this->owner->email,
            'password' => 'secret-password',
            'device_name' => 'confused client',
            'abilities' => ['everything'],
        ])->assertStatus(422)->assertJsonValidationErrors('abilities.0');
    }

    // ── Enforcement ──────────────────────────────────────────────────────

    public function test_a_read_token_can_read_but_not_write(): void
    {
        Sanctum::actingAs($this->owner, [TokenAbilities::READ]);

        $this->getJson('/api/v1/contacts')->assertOk();

        $this->postJson('/api/v1/contacts', ['name' => 'Nope'])->assertForbidden();
    }

    public function test_a_write_token_cannot_move_money(): void
    {
        Sanctum::actingAs($this->owner, [TokenAbilities::READ, TokenAbilities::WRITE]);

        // It can do ordinary work…
        $this->postJson('/api/v1/contacts', ['name' => 'Boulangerie Nkolbisson'])->assertCreated();

        // …but taking a payment is a different trust, and it was not asked for.
        $this->postJson('/api/v1/payments', [
            'document_id' => Str::ulid()->toString(),
            'amount' => 1000,
            'method' => PaymentMethod::Cash->value,
        ])->assertForbidden();
    }

    /**
     * Staff and pay sit behind their own scope rather than under `read`, so a
     * reporting integration does not quietly arrive with the staff file
     * attached.
     */
    public function test_a_read_token_does_not_reach_the_staff_file(): void
    {
        Sanctum::actingAs($this->owner, [TokenAbilities::READ]);

        $this->getJson('/api/v1/employees')->assertForbidden();
        $this->getJson('/api/v1/payroll/runs')->assertForbidden();
    }

    public function test_a_people_token_reaches_the_staff_file(): void
    {
        Sanctum::actingAs($this->owner, [TokenAbilities::PEOPLE]);

        $this->getJson('/api/v1/employees')->assertOk();
    }

    /**
     * The ceiling, proven from the other side: every scope there is, held by
     * a cashier, is still only a cashier.
     */
    public function test_scopes_cannot_lift_a_token_above_its_user(): void
    {
        $cashier = User::factory()->create();
        $this->joinCompany($this->company, $cashier, 'cashier');
        $cashier->forceFill(['current_company_id' => $this->company->id])->save();

        Sanctum::actingAs($cashier, TokenAbilities::all());

        // The scope allows it; the permission catalogue does not.
        $this->getJson('/api/v1/employees')->assertForbidden();
        $this->postJson('/api/v1/items', ['name' => 'Nope', 'price' => 1])->assertForbidden();
    }

    /**
     * Whatever a token was minted for, it must always be able to say who it is
     * and to hand itself back — otherwise a narrow token cannot be revoked by
     * the thing holding it.
     */
    public function test_identity_and_revocation_are_outside_the_scope_checks(): void
    {
        // A real token rather than Sanctum::actingAs: the test double is a
        // mock whose `abilities` property is never populated, so it could not
        // show that the endpoint reports back what the token actually holds.
        $token = $this->postJson('/api/v1/tokens', [
            'email' => $this->owner->email,
            'password' => 'secret-password',
            'device_name' => 'reporting dashboard',
            'abilities' => [TokenAbilities::READ],
        ])->json('token');

        $headers = ['Authorization' => 'Bearer '.$token];

        $this->getJson('/api/v1/user', $headers)
            ->assertOk()
            ->assertJsonPath('data.abilities', ['read']);

        // And it can hand itself back, or a narrow token could never be
        // revoked by the thing holding it.
        $this->deleteJson('/api/v1/tokens/current', [], $headers)->assertOk();

        // Asserted against the store rather than by replaying the request:
        // the auth guard caches its resolved user for the life of the test
        // process, which a real request lifecycle would not.
        $this->assertSame(0, $this->owner->tokens()->count());
    }
}
