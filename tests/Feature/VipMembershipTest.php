<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Role;
use App\Models\User;
use App\Models\VipMembership;
use App\Models\VipTier;
use App\Services\VipMemberships;
use App\Support\Accounting\ChartOfAccounts;
use App\Support\CurrentCompany;
use Database\Seeders\RolePermissionSeeder;
use App\Jobs\DeliverWebhook;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Support\WebhookEvents;
use Illuminate\Support\Facades\Queue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Selling a VIP tier, and what that sale is worth on the books and on the
 * invoices that follow it.
 */
class VipMembershipTest extends TestCase
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

        ChartOfAccounts::seed($this->company);

        $this->company->forceFill(['modules' => ['vip' => true]])->save();

        $this->customer = Contact::create(['name' => 'Un Client', 'balance' => 0]);
    }

    protected function service(): VipMemberships
    {
        return app(VipMemberships::class);
    }

    public function test_selling_a_membership_raises_an_invoice_and_starts_the_term(): void
    {
        $tier = VipTier::factory()->create();

        $membership = $this->service()->sell($this->customer, $tier, $this->owner);

        $this->assertSame(VipMembership::ACTIVE, $membership->status);
        $this->assertSame('Gold', $membership->tier_name);
        $this->assertEquals(15, $membership->discount_percent);
        $this->assertNotNull($membership->document_id);
        $this->assertTrue($membership->ends_on->isSameDay(now()->addYear()->subDay()));

        $document = $membership->document;
        $this->assertSame(DocumentStatus::Issued, $document->status);
        $this->assertNotNull($document->number);
    }

    public function test_changing_a_tier_does_not_change_an_existing_membership(): void
    {
        $tier = VipTier::factory()->create();

        $membership = $this->service()->sell($this->customer, $tier, $this->owner);

        $tier->update(['discount_percent' => 40, 'price' => 999999]);

        $membership->refresh();
        $this->assertEquals(15, $membership->discount_percent);
        $this->assertEquals(50000, $membership->price_paid);
    }

    public function test_buying_while_active_extends_from_the_current_end_date(): void
    {
        $tier = VipTier::factory()->create();

        $first = $this->service()->sell($this->customer, $tier, $this->owner);
        $second = $this->service()->sell($this->customer, $tier, $this->owner);

        $this->assertTrue($second->starts_on->isSameDay($first->ends_on->copy()->addDay()));

        $first->refresh();
        $this->assertSame(VipMembership::EXPIRED, $first->status);
    }

    public function test_an_expired_membership_gives_no_discount(): void
    {
        $membership = VipMembership::factory()->expired()->create([
            'company_id' => $this->company->id,
            'contact_id' => $this->customer->id,
        ]);

        $this->assertEquals(0.0, $membership->effectiveDiscount());
        $this->assertEquals(0.0, $this->service()->discountFor($this->customer));
    }

    public function test_the_active_membership_supplies_the_discount(): void
    {
        VipMembership::factory()->create([
            'company_id' => $this->company->id,
            'contact_id' => $this->customer->id,
        ]);

        $this->assertEquals(15.0, $this->service()->discountFor($this->customer));
    }

    public function test_cancelling_stops_the_benefit_and_keeps_the_record(): void
    {
        $membership = VipMembership::factory()->create([
            'company_id' => $this->company->id,
            'contact_id' => $this->customer->id,
        ]);

        $this->service()->cancel($membership, 'Customer requested a refund', $this->owner);

        $membership->refresh();
        $this->assertSame(VipMembership::CANCELLED, $membership->status);
        $this->assertSame('Customer requested a refund', $membership->cancelled_reason);
        $this->assertEquals(0.0, $this->service()->discountFor($this->customer));
        $this->assertDatabaseHas('vip_memberships', ['id' => $membership->id]);
    }

    public function test_the_expiry_sweep_marks_lapsed_memberships(): void
    {
        $membership = VipMembership::factory()->expired()->create([
            'company_id' => $this->company->id,
            'contact_id' => $this->customer->id,
        ]);

        $count = $this->service()->expireLapsed();

        $this->assertSame(1, $count);
        $this->assertSame(VipMembership::EXPIRED, $membership->fresh()->status);
    }

    public function test_an_inactive_tier_cannot_be_sold(): void
    {
        $tier = VipTier::factory()->create(['is_active' => false]);

        $this->expectException(RuntimeException::class);

        $this->service()->sell($this->customer, $tier, $this->owner);
    }

    // ── The discount reaching an invoice ─────────────────────────────────

    public function test_an_invoice_for_a_member_carries_the_tier_discount(): void
    {
        VipMembership::factory()->create([
            'company_id' => $this->company->id,
            'contact_id' => $this->customer->id,
        ]);

        Sanctum::actingAs($this->owner, ['*']);

        $this->postJson('/api/v1/documents', [
            'type' => 'invoice',
            'contact_id' => $this->customer->id,
            'lines' => [['description' => 'Dinner', 'quantity' => 1, 'unit_price' => 100000]],
        ])->assertCreated()
            ->assertJsonPath('data.subtotal', fn ($v) => (float) $v === 100000.0)
            ->assertJsonPath('data.discount_total', fn ($v) => (float) $v === 15000.0);
    }

    public function test_an_invoice_for_a_non_member_carries_no_discount(): void
    {
        Sanctum::actingAs($this->owner, ['*']);

        $this->postJson('/api/v1/documents', [
            'type' => 'invoice',
            'contact_id' => $this->customer->id,
            'lines' => [['description' => 'Dinner', 'quantity' => 1, 'unit_price' => 100000]],
        ])->assertCreated()
            ->assertJsonPath('data.discount_total', fn ($v) => (float) $v === 0.0);
    }

    /** The tier proposes; whoever raises the invoice decides. */
    public function test_an_explicit_discount_overrides_the_tier(): void
    {
        VipMembership::factory()->create([
            'company_id' => $this->company->id,
            'contact_id' => $this->customer->id,
        ]);

        Sanctum::actingAs($this->owner, ['*']);

        $this->postJson('/api/v1/documents', [
            'type' => 'invoice',
            'contact_id' => $this->customer->id,
            'discount_percent' => 0,
            'lines' => [['description' => 'Dinner', 'quantity' => 1, 'unit_price' => 100000]],
        ])->assertCreated()
            ->assertJsonPath('data.discount_total', fn ($v) => (float) $v === 0.0);
    }

    /**
     * A lapsed member is charged the full price. The nightly sweep may not have
     * run, so this is the case that proves the dates decide rather than the
     * status column.
     */
    public function test_a_lapsed_member_is_charged_in_full(): void
    {
        VipMembership::factory()->expired()->create([
            'company_id' => $this->company->id,
            'contact_id' => $this->customer->id,
        ]);

        Sanctum::actingAs($this->owner, ['*']);

        $this->postJson('/api/v1/documents', [
            'type' => 'invoice',
            'contact_id' => $this->customer->id,
            'lines' => [['description' => 'Dinner', 'quantity' => 1, 'unit_price' => 100000]],
        ])->assertCreated()
            ->assertJsonPath('data.discount_total', fn ($v) => (float) $v === 0.0);
    }

    // ── The screens ──────────────────────────────────────────────────────

    public function test_the_members_screen_renders(): void
    {
        VipMembership::factory()->create([
            'company_id' => $this->company->id,
            'contact_id' => $this->customer->id,
        ]);

        $this->actingAs($this->owner)
            ->get(route('vip.members'))
            ->assertOk()
            ->assertSee('VIP members')
            ->assertSee($this->customer->name);
    }

    public function test_a_membership_can_be_sold_from_the_screen(): void
    {
        $tier = VipTier::factory()->create(['company_id' => $this->company->id]);

        Livewire::actingAs($this->owner)
            ->test(\App\Livewire\Vip\Members::class)
            ->set('sellTo', $this->customer->id)
            ->set('sellTier', $tier->id)
            ->call('sell')
            ->assertHasNoErrors();

        $this->assertSame(1, VipMembership::count());
    }

    /** A withdrawn tier must not be sellable, and the reason must be shown. */
    public function test_selling_a_withdrawn_tier_shows_the_reason(): void
    {
        $tier = VipTier::factory()->create([
            'company_id' => $this->company->id,
            'is_active' => false,
        ]);

        Livewire::actingAs($this->owner)
            ->test(\App\Livewire\Vip\Members::class)
            ->set('sellTo', $this->customer->id)
            ->set('sellTier', $tier->id)
            ->call('sell')
            ->assertHasErrors('sellTier');

        $this->assertSame(0, VipMembership::count());
    }

    public function test_a_tier_can_be_added_and_withdrawn(): void
    {
        Livewire::actingAs($this->owner)
            ->test(\App\Livewire\Vip\Tiers::class)
            ->set('name', 'Platinum')
            ->set('price', '120000')
            ->set('periodMonths', '12')
            ->set('discountPercent', '25')
            ->call('save')
            ->assertHasNoErrors();

        $tier = VipTier::where('name', 'Platinum')->firstOrFail();
        $this->assertTrue((bool) $tier->is_active);

        Livewire::actingAs($this->owner)
            ->test(\App\Livewire\Vip\Tiers::class)
            ->call('withdraw', $tier->id);

        $this->assertFalse((bool) $tier->fresh()->is_active);
    }

    public function test_a_discount_over_a_hundred_percent_is_refused(): void
    {
        Livewire::actingAs($this->owner)
            ->test(\App\Livewire\Vip\Tiers::class)
            ->set('name', 'Impossible')
            ->set('price', '1000')
            ->set('periodMonths', '12')
            ->set('discountPercent', '150')
            ->call('save')
            ->assertHasErrors('discountPercent');
    }

    public function test_a_cashier_cannot_reach_the_members_screen(): void
    {
        $cashier = User::factory()->create();
        $this->joinCompany($this->company, $cashier, 'cashier');
        $cashier->forceFill(['current_company_id' => $this->company->id])->save();

        $this->actingAs($cashier)->get(route('vip.members'))->assertForbidden();
    }

    /** With the module off, the screens close even for the owner. */
    public function test_the_screens_close_when_the_module_is_off(): void
    {
        $this->company->forceFill(['modules' => ['vip' => false]])->save();

        $this->actingAs($this->owner)->get(route('vip.members'))->assertForbidden();
        $this->actingAs($this->owner)->get(route('vip.tiers'))->assertForbidden();
    }

    // ── The nightly sweep ────────────────────────────────────────────────

    public function test_the_expiry_command_reports_what_it_did(): void
    {
        VipMembership::factory()->expired()->create([
            'company_id' => $this->company->id,
            'contact_id' => $this->customer->id,
        ]);

        $this->artisan('opes:expire-vip-memberships')
            ->expectsOutputToContain('Expired 1 membership.')
            ->assertSuccessful();

        $this->assertSame(VipMembership::EXPIRED, VipMembership::first()->status);
    }

    public function test_the_expiry_command_is_quiet_when_there_is_nothing_to_do(): void
    {
        $this->artisan('opes:expire-vip-memberships')
            ->expectsOutputToContain('No memberships to expire.')
            ->assertSuccessful();
    }

    /**
     * A membership still inside its term is left alone. The sweep runs nightly
     * across every business, so an off-by-one here would cancel live
     * memberships wholesale.
     */
    public function test_the_sweep_leaves_a_live_membership_alone(): void
    {
        VipMembership::factory()->create([
            'company_id' => $this->company->id,
            'contact_id' => $this->customer->id,
        ]);

        $this->assertSame(0, $this->service()->expireLapsed());
        $this->assertSame(VipMembership::ACTIVE, VipMembership::first()->status);
    }

    /** A membership ending today has not ended yet. */
    public function test_a_membership_ending_today_survives_the_sweep(): void
    {
        VipMembership::factory()->create([
            'company_id' => $this->company->id,
            'contact_id' => $this->customer->id,
            'ends_on' => now()->toDateString(),
        ]);

        $this->assertSame(0, $this->service()->expireLapsed());
    }

    // ── Telling other systems ────────────────────────────────────────────

    /** @param array<int, string> $events */
    protected function endpoint(array $events): WebhookEndpoint
    {
        $endpoint = new WebhookEndpoint;

        $endpoint->forceFill([
            'company_id' => $this->company->id,
            'url' => 'https://hooks.example.com/opes',
            'secret' => WebhookEndpoint::newSecret(),
            'events' => $events,
            'is_active' => true,
        ])->save();

        return $endpoint;
    }

    public function test_selling_tells_a_subscribed_endpoint(): void
    {
        Queue::fake();

        $this->endpoint([WebhookEvents::VIP_SOLD]);
        $tier = VipTier::factory()->create(['company_id' => $this->company->id]);

        $this->service()->sell($this->customer, $tier, $this->owner);

        Queue::assertPushed(DeliverWebhook::class);

        $delivery = WebhookDelivery::query()->firstOrFail();

        $this->assertSame(WebhookEvents::VIP_SOLD, $delivery->event);
        $this->assertSame('Gold', $delivery->payload['data']['tier_name']);
        $this->assertEquals(15, $delivery->payload['data']['discount_percent']);
    }

    /**
     * The sweep reads its rows before updating them precisely so it can say
     * WHICH membership lapsed. A bulk update knows only how many did, and a
     * subscriber wanting to win somebody back cannot act on a count.
     */
    public function test_the_sweep_says_which_membership_lapsed(): void
    {
        Queue::fake();

        $this->endpoint([WebhookEvents::VIP_EXPIRED]);

        $membership = VipMembership::factory()->expired()->create([
            'company_id' => $this->company->id,
            'contact_id' => $this->customer->id,
        ]);

        $this->service()->expireLapsed();

        $delivery = WebhookDelivery::query()->firstOrFail();

        $this->assertSame(WebhookEvents::VIP_EXPIRED, $delivery->event);
        $this->assertSame($membership->id, $delivery->payload['data']['id']);
        $this->assertSame($this->customer->id, $delivery->payload['data']['contact_id']);
    }

    public function test_cancelling_carries_the_reason(): void
    {
        Queue::fake();

        $this->endpoint([WebhookEvents::VIP_CANCELLED]);

        $membership = VipMembership::factory()->create([
            'company_id' => $this->company->id,
            'contact_id' => $this->customer->id,
        ]);

        $this->service()->cancel($membership, 'Moved away', $this->owner);

        $delivery = WebhookDelivery::query()->firstOrFail();

        $this->assertSame('Moved away', $delivery->payload['data']['reason']);
    }

    /** An endpoint that did not ask about VIP is not told about it. */
    public function test_an_unsubscribed_endpoint_hears_nothing(): void
    {
        Queue::fake();

        $this->endpoint([WebhookEvents::PAYMENT_RECORDED]);
        $tier = VipTier::factory()->create(['company_id' => $this->company->id]);

        $this->service()->sell($this->customer, $tier, $this->owner);

        $this->assertSame(
            0,
            WebhookDelivery::query()->where('event', WebhookEvents::VIP_SOLD)->count()
        );
    }
}
