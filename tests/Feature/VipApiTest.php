<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Role;
use App\Models\User;
use App\Models\VipMembership;
use App\Models\VipTier;
use App\Support\Accounting\ChartOfAccounts;
use App\Support\CurrentCompany;
use App\Support\TokenAbilities;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The VIP programme over the token API.
 *
 * The assertions that carry weight are the refusals: a read token cannot sell,
 * another company's membership does not exist, the module switched off closes
 * every route, and a retried sale charges once.
 */
class VipApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Company $company;

    protected Contact $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->owner = User::factory()->create();
        $this->company = $this->makeCompany('Hotel Sawa', $this->owner);

        $this->joinCompany($this->company, $this->owner, Role::OWNER);
        $this->owner->forceFill(['current_company_id' => $this->company->id])->save();

        app(CurrentCompany::class)->set($this->company);
        ChartOfAccounts::seed($this->company);

        $this->company->forceFill(['modules' => ['vip' => true]])->save();

        $this->customer = Contact::create(['type' => 'customer', 'name' => 'Nodai Felix']);

        Sanctum::actingAs($this->owner, ['*']);
    }

    protected function makeCompany(string $name, User $owner): Company
    {
        return Company::create([
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'name' => $name,
            'owner_id' => $owner->id,
            'currency' => 'XAF',
            'plan' => 'business',
            'account_type' => 'active',
        ]);
    }

    protected function tier(array $attributes = []): VipTier
    {
        return VipTier::factory()->create($attributes + ['company_id' => $this->company->id]);
    }

    // ── Tiers ────────────────────────────────────────────────────────────

    public function test_a_tier_can_be_created_and_listed(): void
    {
        $this->postJson('/api/v1/vip/tiers', [
            'name' => 'Platinum',
            'price' => 120000,
            'period_months' => 12,
            'discount_percent' => 25,
        ])->assertCreated()->assertJsonPath('data.name', 'Platinum');

        $this->getJson('/api/v1/vip/tiers')
            ->assertOk()
            ->assertJsonPath('data.0.discount_percent', fn ($v) => (float) $v === 25.0);
    }

    public function test_a_discount_over_a_hundred_percent_is_refused(): void
    {
        $this->postJson('/api/v1/vip/tiers', [
            'name' => 'Impossible',
            'price' => 1000,
            'period_months' => 12,
            'discount_percent' => 150,
        ])->assertStatus(422)->assertJsonValidationErrors('discount_percent');
    }

    /** Editing changes what future sales get, never what a member already has. */
    public function test_editing_a_tier_leaves_existing_memberships_alone(): void
    {
        $tier = $this->tier();

        $id = $this->postJson('/api/v1/vip/memberships', [
            'contact_id' => $this->customer->id,
            'tier_id' => $tier->id,
        ])->assertCreated()->json('data.id');

        $this->patchJson("/api/v1/vip/tiers/{$tier->id}", ['discount_percent' => 40])->assertOk();

        $this->getJson("/api/v1/vip/memberships/{$id}")
            ->assertOk()
            ->assertJsonPath('data.discount_percent', fn ($v) => (float) $v === 15.0);
    }

    // ── Selling ──────────────────────────────────────────────────────────

    public function test_a_membership_can_be_sold(): void
    {
        $tier = $this->tier();

        $this->postJson('/api/v1/vip/memberships', [
            'contact_id' => $this->customer->id,
            'tier_id' => $tier->id,
        ])->assertCreated()
            ->assertJsonPath('data.tier_name', 'Gold')
            ->assertJsonPath('data.is_active', true)
            // The fee was invoiced, so a caller can follow the money.
            ->assertJsonPath('data.document_id', fn ($v) => $v !== null);
    }

    public function test_a_withdrawn_tier_cannot_be_sold(): void
    {
        $tier = $this->tier(['is_active' => false]);

        $this->postJson('/api/v1/vip/memberships', [
            'contact_id' => $this->customer->id,
            'tier_id' => $tier->id,
        ])->assertStatus(422);

        $this->assertSame(0, VipMembership::count());
    }

    /** A retry after a dropped connection must not sell and charge twice. */
    public function test_selling_twice_with_one_key_sells_once(): void
    {
        $tier = $this->tier();

        $key = ['Idempotency-Key' => (string) Str::uuid()];
        $body = ['contact_id' => $this->customer->id, 'tier_id' => $tier->id];

        $first = $this->postJson('/api/v1/vip/memberships', $body, $key)->assertCreated();

        $this->postJson('/api/v1/vip/memberships', $body, $key)
            ->assertCreated()
            ->assertHeader('Idempotent-Replay', 'true')
            ->assertJsonPath('data.id', $first->json('data.id'));

        $this->assertSame(1, VipMembership::count());
    }

    public function test_cancelling_needs_a_reason(): void
    {
        $tier = $this->tier();

        $id = $this->postJson('/api/v1/vip/memberships', [
            'contact_id' => $this->customer->id,
            'tier_id' => $tier->id,
        ])->json('data.id');

        $this->postJson("/api/v1/vip/memberships/{$id}/cancel", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->postJson("/api/v1/vip/memberships/{$id}/cancel", ['reason' => 'Moved away'])
            ->assertOk()
            ->assertJsonPath('data.status', VipMembership::CANCELLED)
            ->assertJsonPath('data.is_active', false);
    }

    // ── Scopes and boundaries ────────────────────────────────────────────

    public function test_a_read_token_cannot_sell_or_edit(): void
    {
        $tier = $this->tier();

        Sanctum::actingAs($this->owner, [TokenAbilities::READ]);

        $this->getJson('/api/v1/vip/tiers')->assertOk();

        $this->postJson('/api/v1/vip/memberships', [
            'contact_id' => $this->customer->id,
            'tier_id' => $tier->id,
        ])->assertForbidden();

        $this->postJson('/api/v1/vip/tiers', [
            'name' => 'Nope', 'price' => 1, 'period_months' => 1, 'discount_percent' => 1,
        ])->assertForbidden();
    }

    /**
     * Adding a tier is ordinary work; selling one takes money. A token trusted
     * with the first is not thereby trusted with the second.
     */
    public function test_a_write_token_can_manage_tiers_but_not_sell(): void
    {
        $tier = $this->tier();

        Sanctum::actingAs($this->owner, [TokenAbilities::READ, TokenAbilities::WRITE]);

        $this->postJson('/api/v1/vip/tiers', [
            'name' => 'Silver', 'price' => 20000, 'period_months' => 6, 'discount_percent' => 5,
        ])->assertCreated();

        $this->postJson('/api/v1/vip/memberships', [
            'contact_id' => $this->customer->id,
            'tier_id' => $tier->id,
        ])->assertForbidden();
    }

    public function test_a_cashier_cannot_reach_the_programme(): void
    {
        $cashier = User::factory()->create();
        $this->joinCompany($this->company, $cashier, 'cashier');
        $cashier->forceFill(['current_company_id' => $this->company->id])->save();

        Sanctum::actingAs($cashier, ['*']);

        $this->getJson('/api/v1/vip/tiers')->assertForbidden();
    }

    public function test_the_module_switched_off_closes_every_route(): void
    {
        $this->company->forceFill(['modules' => ['vip' => false]])->save();

        $this->getJson('/api/v1/vip/tiers')->assertForbidden();
        $this->getJson('/api/v1/vip/memberships')->assertForbidden();
    }

    /** Another company's membership does not exist, rather than being refused. */
    public function test_another_companys_membership_is_not_reachable(): void
    {
        $stranger = User::factory()->create();
        $other = $this->makeCompany('Other Sarl', $stranger);

        $theirContact = Contact::withoutGlobalScopes()->create([
            'company_id' => $other->id, 'type' => 'customer', 'name' => 'Theirs',
        ]);

        $theirs = VipMembership::withoutGlobalScopes()->create([
            'company_id' => $other->id,
            'contact_id' => $theirContact->id,
            'tier_name' => 'Gold',
            'discount_percent' => 15,
            'price_paid' => 50000,
            'currency' => 'XAF',
            'starts_on' => now()->toDateString(),
            'ends_on' => now()->addYear()->toDateString(),
            'status' => VipMembership::ACTIVE,
        ]);

        $this->getJson('/api/v1/vip/memberships')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/vip/memberships/{$theirs->id}")->assertNotFound();
        $this->postJson("/api/v1/vip/memberships/{$theirs->id}/cancel", ['reason' => 'no'])->assertNotFound();

        $this->assertSame(VipMembership::ACTIVE, $theirs->fresh()->status);
    }
}
