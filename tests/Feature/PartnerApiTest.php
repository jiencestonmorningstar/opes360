<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PartnerClient;
use App\Models\PartnerPayout;
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
 * The secretariat programme over the token API.
 *
 * The rule worth protecting here is not a role rule. The programme is a
 * property of the *account*: a plain business has no client book to manage and
 * no balance to withdraw, so every `partners.*` ability is denied to a company
 * that is not a secretariat before any role is consulted. An Owner of an
 * ordinary business is refused exactly as a cashier would be, and that is the
 * intended answer rather than a gap.
 */
class PartnerApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $partner;

    protected Company $secretariat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->partner = User::factory()->create();
        $this->secretariat = $this->makeCompany('Bureau Bonanjo', $this->partner, 'secretariat');

        $this->joinCompany($this->secretariat, $this->partner, Role::OWNER);
        $this->partner->forceFill(['current_company_id' => $this->secretariat->id])->save();

        app(CurrentCompany::class)->set($this->secretariat);

        Sanctum::actingAs($this->partner, ['*']);
    }

    protected function makeCompany(string $name, User $owner, string $kind = 'business'): Company
    {
        return Company::create([
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'name' => $name,
            'owner_id' => $owner->id,
            'currency' => 'XAF',
            'plan' => 'business',
            'account_type' => 'active',
            'kind' => $kind,
        ]);
    }

    // ── The client book ──────────────────────────────────────────────────

    public function test_a_client_can_be_added_and_listed(): void
    {
        $this->postJson('/api/v1/partners/clients', [
            'name' => 'Boulangerie Nkolbisson',
            'contact_name' => 'Marie Ngo',
            'phone' => '+237670000000',
            'city' => 'Yaoundé',
        ])->assertCreated()->assertJsonPath('data.name', 'Boulangerie Nkolbisson');

        $this->getJson('/api/v1/partners/clients')
            ->assertOk()
            ->assertJsonPath('data.0.contact_name', 'Marie Ngo')
            ->assertJsonPath('data.0.converted', false);
    }

    public function test_a_client_needs_a_business_name(): void
    {
        $this->postJson('/api/v1/partners/clients', ['phone' => '+237670000000'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_clients_can_be_searched_and_filtered_by_conversion(): void
    {
        PartnerClient::create(['company_id' => $this->secretariat->id, 'name' => 'Garage Akwa']);
        PartnerClient::create([
            'company_id' => $this->secretariat->id,
            'name' => 'Boulangerie Nkolbisson',
            'converted_at' => now(),
        ]);

        $this->getJson('/api/v1/partners/clients?q=Garage')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Garage Akwa');

        $this->getJson('/api/v1/partners/clients?converted=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.converted', true);
    }

    /**
     * The invite token is a capability — whoever holds it can claim the
     * referral — so a read of the book must not hand one out per row.
     */
    public function test_the_invite_token_is_never_returned(): void
    {
        PartnerClient::create([
            'company_id' => $this->secretariat->id,
            'name' => 'Garage Akwa',
            'invite_token' => 'super-secret-token',
        ]);

        $body = $this->getJson('/api/v1/partners/clients')->assertOk()->json('data.0');

        $this->assertArrayNotHasKey('invite_token', $body);
        $this->getJson('/api/v1/partners/clients')->assertDontSee('super-secret-token');
    }

    // ── Earnings ─────────────────────────────────────────────────────────

    public function test_the_earnings_summary_comes_back(): void
    {
        $this->getJson('/api/v1/partners/earnings')
            ->assertOk()
            ->assertJsonStructure(['data' => ['earned', 'fees', 'withdrawn', 'balance', 'minimum', 'can_request', 'clients']]);
    }

    public function test_commissions_and_payouts_are_listable(): void
    {
        $this->getJson('/api/v1/partners/commissions')->assertOk()->assertJsonStructure(['data']);
        $this->getJson('/api/v1/partners/payouts')->assertOk()->assertJsonStructure(['data']);
    }

    // ── Getting paid ─────────────────────────────────────────────────────

    public function test_a_payout_below_the_minimum_is_refused(): void
    {
        // Nothing earned yet, so the balance is zero.
        $this->postJson('/api/v1/partners/payouts', [
            'method' => 'mtn',
            'destination' => '+237670000000',
        ])->assertStatus(422)->assertJsonPath('minimum', (int) config('opes.partners.payout_minimum'));

        $this->assertSame(0, PartnerPayout::count());
    }

    /**
     * The amount is not a parameter, so there is nothing for a caller to
     * inflate — it is recomputed from the ledger at the moment of the request.
     */
    public function test_the_requested_amount_cannot_be_named_by_the_caller(): void
    {
        $this->creditCommission(50000);

        $this->postJson('/api/v1/partners/payouts', [
            'method' => 'mtn',
            'destination' => '+237670000000',
            'amount' => 999999999,
        ])->assertCreated()
            // Compared numerically: the franc has no minor unit, so a whole
            // amount encodes as a JSON integer rather than 50000.0.
            ->assertJsonPath('data.amount', fn ($v) => (float) $v === 50000.0);
    }

    public function test_a_payout_needs_somewhere_to_send_the_money(): void
    {
        $this->creditCommission(50000);

        $this->postJson('/api/v1/partners/payouts', ['method' => 'mtn'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('destination');
    }

    /** A retried request must not ask to be paid twice for the same earnings. */
    public function test_a_payout_request_is_idempotent_under_a_key(): void
    {
        $this->creditCommission(50000);

        $key = ['Idempotency-Key' => (string) Str::uuid()];
        $body = ['method' => 'mtn', 'destination' => '+237670000000'];

        $first = $this->postJson('/api/v1/partners/payouts', $body, $key)->assertCreated();

        $this->postJson('/api/v1/partners/payouts', $body, $key)
            ->assertCreated()
            ->assertHeader('Idempotent-Replay', 'true')
            ->assertJsonPath('data.id', $first->json('data.id'));

        $this->assertSame(1, PartnerPayout::count());
    }

    // ── Who the programme is for ─────────────────────────────────────────

    /**
     * The load-bearing rule: an ordinary business has no programme, and its
     * Owner is refused as firmly as anybody else.
     */
    public function test_an_ordinary_business_owner_is_refused_everything(): void
    {
        $owner = User::factory()->create();
        $plain = $this->makeCompany('Acme Sarl', $owner);

        $this->joinCompany($plain, $owner, Role::OWNER);
        $owner->forceFill(['current_company_id' => $plain->id])->save();

        app(CurrentCompany::class)->set($plain);
        Sanctum::actingAs($owner, ['*']);

        $this->getJson('/api/v1/partners/clients')->assertForbidden();
        $this->getJson('/api/v1/partners/earnings')->assertForbidden();
        $this->postJson('/api/v1/partners/clients', ['name' => 'Nope'])->assertForbidden();
        $this->postJson('/api/v1/partners/payouts', [
            'method' => 'mtn',
            'destination' => '+237670000000',
        ])->assertForbidden();
    }

    /** Another secretariat's book is not visible, and not distinguishable. */
    public function test_another_partners_client_book_is_not_reachable(): void
    {
        $stranger = User::factory()->create();
        $other = $this->makeCompany('Bureau Douala', $stranger, 'secretariat');

        $theirs = PartnerClient::create(['company_id' => $other->id, 'name' => 'Their Client']);

        $this->getJson('/api/v1/partners/clients')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/partners/clients/{$theirs->id}")->assertNotFound();
        $this->patchJson("/api/v1/partners/clients/{$theirs->id}", ['name' => 'Hijacked'])->assertNotFound();

        $this->assertSame('Their Client', $theirs->fresh()->name);
    }

    // ── Scopes ───────────────────────────────────────────────────────────

    public function test_a_read_token_cannot_add_a_client_or_ask_to_be_paid(): void
    {
        Sanctum::actingAs($this->partner, [TokenAbilities::READ]);

        $this->getJson('/api/v1/partners/clients')->assertOk();
        $this->postJson('/api/v1/partners/clients', ['name' => 'Nope'])->assertForbidden();
        $this->postJson('/api/v1/partners/payouts', [
            'method' => 'mtn',
            'destination' => '+237670000000',
        ])->assertForbidden();
    }

    /**
     * Adding a name to a book and asking for money are different trusts, so a
     * write token does not carry the second.
     */
    public function test_a_write_token_can_manage_clients_but_not_withdraw(): void
    {
        Sanctum::actingAs($this->partner, [TokenAbilities::READ, TokenAbilities::WRITE]);

        $this->postJson('/api/v1/partners/clients', ['name' => 'Garage Akwa'])->assertCreated();

        $this->postJson('/api/v1/partners/payouts', [
            'method' => 'mtn',
            'destination' => '+237670000000',
        ])->assertForbidden();
    }

    /**
     * Puts a settled commission on the books so there is a balance to pay.
     *
     * A commission is a share of a subscription payment and the column is not
     * nullable, so the payment it came from is created too rather than the
     * constraint being worked around — the shape of the fixture is the shape
     * of the real thing.
     */
    protected function creditCommission(int $amount): void
    {
        $payment = \App\Models\SubscriptionPayment::create([
            'company_id' => $this->secretariat->id,
            'plan' => 'growth',
            'billing_cycle' => 'monthly',
            'amount' => $amount * 10,
            'currency' => 'XAF',
            'provider' => 'mtn',
            // The provider's own reference for the transaction, which is how a
            // callback finds the payment again. Not nullable, so the fixture
            // carries one.
            'external_id' => (string) Str::uuid(),
            'status' => 'succeeded',
            'paid_at' => now(),
        ]);

        \App\Models\PartnerCommission::create([
            'company_id' => $this->secretariat->id,
            'source_company_id' => $this->secretariat->id,
            'subscription_payment_id' => $payment->id,
            'amount' => $amount,
            'rate' => 0.1,
            'base_amount' => $amount * 10,
            'currency' => 'XAF',
            'status' => 'earned',
        ]);
    }
}
