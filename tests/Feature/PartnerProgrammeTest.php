<?php

namespace Tests\Feature;

use App\Livewire\Onboarding\Register;
use App\Livewire\Partners\Clients;
use App\Livewire\Partners\ClientShow;
use App\Livewire\Partners\Earnings;
use App\Models\CardIssuance;
use App\Models\Company;
use App\Models\PartnerClient;
use App\Models\PartnerCommission;
use App\Models\PartnerPayout;
use App\Models\PlatformAdmin;
use App\Models\Role;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Notifications\PartnerCommissionEarnedNotification;
use App\Notifications\PartnerPayoutSettledNotification;
use App\Services\Partners\PartnerLedger;
use App\Services\Partners\PartnerProgramme;
use App\Support\CurrentCompany;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The secretariat programme: a print shop charged per card, earning a recurring
 * share of every business it enrols.
 */
class PartnerProgrammeTest extends TestCase
{
    use RefreshDatabase;

    protected User $partnerOwner;

    protected Company $partner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->partnerOwner = User::factory()->create();
        $this->partner = $this->makeCompany('Secretariat Bonamoussadi', 'secretariat', $this->partnerOwner);
        $this->joinCompany($this->partner, $this->partnerOwner, Role::OWNER);
        app(CurrentCompany::class)->set($this->partner);
    }

    protected function makeCompany(string $name, string $kind = 'business', ?User $owner = null): Company
    {
        return Company::create([
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'name' => $name,
            'owner_id' => ($owner ?? User::factory()->create())->id,
            'currency' => 'XAF',
            'plan' => 'basic',
            'account_type' => 'active',
            'kind' => $kind,
        ]);
    }

    protected function settledPayment(Company $business, int $amount = 9000): SubscriptionPayment
    {
        return SubscriptionPayment::create([
            'company_id' => $business->id,
            'plan' => 'growth',
            'billing_cycle' => 'monthly',
            'amount' => $amount,
            'currency' => 'XAF',
            'provider' => 'mtn_momo',
            'external_id' => (string) Str::uuid(),
            'status' => 'successful',
        ]);
    }

    // ─────────────────────────────────────────────── account kind ──

    public function test_the_partner_kind_is_independent_of_the_account_lifecycle(): void
    {
        // account_type carries demo|trial|active and gates plan entitlements.
        // Folding "secretariat" into it would strip a partner of every module
        // the moment it stopped reading 'active'.
        $this->partner->forceFill(['account_type' => 'trial'])->save();

        $this->assertTrue($this->partner->fresh()->isSecretariat());
        $this->assertSame('trial', $this->partner->fresh()->account_type);
    }

    public function test_a_partner_code_is_minted_once_and_kept(): void
    {
        $code = $this->partner->partnerCode();

        $this->assertStringStartsWith('OPS-', $code);
        $this->assertSame($code, $this->partner->fresh()->partnerCode());
    }

    /** Read down a phone line all day, so no vowels and no ambiguous glyphs. */
    public function test_a_partner_code_cannot_spell_anything(): void
    {
        $code = substr($this->partner->partnerCode(), 4);

        $this->assertSame(5, strlen($code));
        $this->assertDoesNotMatchRegularExpression('/[AEIOU0]/', $code);
    }

    // ────────────────────────────────────────────────── referrals ──

    public function test_a_client_invite_token_resolves_to_its_partner(): void
    {
        $client = PartnerClient::create(['name' => 'Boulangerie Nkolbisson']);

        $resolved = app(PartnerProgramme::class)->resolveReferral($client->invite_token);

        $this->assertSame($this->partner->id, $resolved['partner']->id);
        $this->assertSame($client->id, $resolved['client']->id);
    }

    public function test_a_partner_code_also_resolves_and_is_case_insensitive(): void
    {
        $code = $this->partner->partnerCode();

        $resolved = app(PartnerProgramme::class)->resolveReferral(strtolower($code));

        $this->assertSame($this->partner->id, $resolved['partner']->id);
        $this->assertNull($resolved['client']);
    }

    public function test_an_unknown_referral_resolves_to_nothing_rather_than_throwing(): void
    {
        $this->assertNull(app(PartnerProgramme::class)->resolveReferral('not-a-real-code'));
        $this->assertNull(app(PartnerProgramme::class)->resolveReferral(null));
        $this->assertNull(app(PartnerProgramme::class)->resolveReferral('  '));
    }

    public function test_attributing_a_signup_marks_the_client_converted(): void
    {
        $client = PartnerClient::create(['name' => 'Garage Akwa']);
        $business = $this->makeCompany('Garage Akwa Ltd');

        $this->assertTrue(app(PartnerProgramme::class)->attribute($business, $client->invite_token));

        $this->assertSame($this->partner->id, $business->fresh()->referred_by_company_id);
        $this->assertSame($business->id, $client->fresh()->converted_company_id);
        $this->assertNotNull($client->fresh()->converted_at);
    }

    /**
     * Otherwise a second partner sending the same person a link could take a
     * referral someone else earned, simply by being later.
     */
    public function test_a_business_cannot_be_re_attributed_to_a_second_partner(): void
    {
        $business = $this->makeCompany('Pharmacie Bonapriso');
        app(PartnerProgramme::class)->attribute($business, $this->partner->partnerCode());

        $rival = $this->makeCompany('Rival Secretariat', 'secretariat');

        $this->assertFalse(app(PartnerProgramme::class)->attribute($business, $rival->partnerCode()));
        $this->assertSame($this->partner->id, $business->fresh()->referred_by_company_id);
    }

    public function test_a_partner_cannot_refer_itself(): void
    {
        $this->assertFalse(
            app(PartnerProgramme::class)->attribute($this->partner, $this->partner->partnerCode())
        );

        $this->assertNull($this->partner->fresh()->referred_by_company_id);
    }

    public function test_registering_with_a_ref_attributes_the_new_business(): void
    {
        $client = PartnerClient::create(['name' => 'Coiffure Bépanda']);

        // Registration runs with no current company; the programme has to work
        // outside the tenant scope entirely.
        app(CurrentCompany::class)->set(null);

        Livewire::test(Register::class, ['ref' => $client->invite_token])
            ->set('name', 'Marie Nkolo')
            ->set('email', 'marie@example.cm')
            ->set('password', 'correct-horse-battery')
            ->set('passwordConfirmation', 'correct-horse-battery')
            ->call('continueToBusiness')
            ->set('businessName', 'Coiffure Bépanda')
            ->set('currency', 'XAF')
            ->call('finish');

        $business = Company::where('name', 'Coiffure Bépanda')->firstOrFail();

        $this->assertSame($this->partner->id, $business->referred_by_company_id);
        $this->assertSame($business->id, $client->fresh()->converted_company_id);
    }

    public function test_registering_with_a_bad_ref_still_creates_the_account(): void
    {
        app(CurrentCompany::class)->set(null);

        Livewire::test(Register::class, ['ref' => 'garbage'])
            ->set('name', 'Ada Obi')
            ->set('email', 'ada@example.cm')
            ->set('password', 'correct-horse-battery')
            ->set('passwordConfirmation', 'correct-horse-battery')
            ->call('continueToBusiness')
            ->set('businessName', 'Ada Trading')
            ->set('currency', 'XAF')
            ->call('finish');

        $business = Company::where('name', 'Ada Trading')->firstOrFail();

        $this->assertNull($business->referred_by_company_id);
    }

    public function test_the_type_parameter_opens_a_secretariat_account(): void
    {
        app(CurrentCompany::class)->set(null);

        Livewire::test(Register::class, ['type' => 'secretariat'])
            ->set('name', 'Paul Etoa')
            ->set('email', 'paul@example.cm')
            ->set('password', 'correct-horse-battery')
            ->set('passwordConfirmation', 'correct-horse-battery')
            ->call('continueToBusiness')
            ->set('businessName', 'Etoa Secretariat')
            ->set('currency', 'XAF')
            ->call('finish');

        $this->assertTrue(Company::where('name', 'Etoa Secretariat')->firstOrFail()->isSecretariat());
    }

    // ───────────────────────────────────────────────── commission ──

    public function test_a_settled_payment_credits_the_referring_partner(): void
    {
        Notification::fake();

        $business = $this->makeCompany('Boulangerie Nkolbisson');
        app(PartnerProgramme::class)->attribute($business, $this->partner->partnerCode());

        $commission = app(PartnerProgramme::class)->creditCommission($this->settledPayment($business, 9000));

        $this->assertNotNull($commission);
        $this->assertSame(900, $commission->amount);   // 10% of 9,000
        $this->assertSame(9000, $commission->base_amount);
        $this->assertSame($this->partner->id, $commission->company_id);
        $this->assertSame($business->id, $commission->source_company_id);
    }

    public function test_the_partner_owner_is_told_about_a_commission(): void
    {
        Notification::fake();

        $business = $this->makeCompany('Boulangerie Nkolbisson');
        app(PartnerProgramme::class)->attribute($business, $this->partner->partnerCode());
        app(PartnerProgramme::class)->creditCommission($this->settledPayment($business));

        Notification::assertSentTo($this->partnerOwner, PartnerCommissionEarnedNotification::class);
    }

    /**
     * A webhook and a manual status check can settle the same payment. Paying a
     * partner twice for one payment is the failure that matters here.
     */
    public function test_the_same_payment_cannot_be_credited_twice(): void
    {
        Notification::fake();

        $business = $this->makeCompany('Boulangerie Nkolbisson');
        app(PartnerProgramme::class)->attribute($business, $this->partner->partnerCode());
        $payment = $this->settledPayment($business);

        app(PartnerProgramme::class)->creditCommission($payment);
        $second = app(PartnerProgramme::class)->creditCommission($payment);

        $this->assertNull($second);
        $this->assertSame(1, PartnerCommission::query()->acrossAllCompanies()->count());
    }

    public function test_a_pending_payment_earns_nothing(): void
    {
        $business = $this->makeCompany('Boulangerie Nkolbisson');
        app(PartnerProgramme::class)->attribute($business, $this->partner->partnerCode());

        $payment = $this->settledPayment($business);
        $payment->forceFill(['status' => 'pending'])->save();

        $this->assertNull(app(PartnerProgramme::class)->creditCommission($payment->fresh()));
    }

    public function test_an_unreferred_business_earns_nobody_anything(): void
    {
        $business = $this->makeCompany('Nobody Sent Them Ltd');

        $this->assertNull(app(PartnerProgramme::class)->creditCommission($this->settledPayment($business)));
        $this->assertSame(0, PartnerCommission::query()->acrossAllCompanies()->count());
    }

    public function test_the_rate_is_read_from_config_not_hardcoded(): void
    {
        Notification::fake();
        config()->set('opes.partners.commission_rate', 0.25);

        $business = $this->makeCompany('Boulangerie Nkolbisson');
        app(PartnerProgramme::class)->attribute($business, $this->partner->partnerCode());

        $commission = app(PartnerProgramme::class)->creditCommission($this->settledPayment($business, 9000));

        $this->assertSame(2250, $commission->amount);
    }

    // ────────────────────────────────────────────────── issuances ──

    public function test_issuing_a_card_charges_the_partner_the_configured_fee(): void
    {
        $client = PartnerClient::create(['name' => 'Garage Akwa']);

        $issuance = app(PartnerProgramme::class)->recordIssuance(
            $this->partner, 'card', 'azure', 'Garage Akwa', $client, $this->partnerOwner
        );

        $this->assertSame(500, $issuance->fee);
        $this->assertSame('billed', $issuance->status);
        $this->assertSame($client->id, $issuance->partner_client_id);
    }

    /**
     * A statement for March must not change because the price changed in April.
     */
    public function test_a_later_price_change_does_not_rewrite_past_issuances(): void
    {
        $issuance = app(PartnerProgramme::class)->recordIssuance($this->partner, 'card', 'azure', 'Garage Akwa');

        config()->set('opes.partners.card_fee', 900);

        $this->assertSame(500, $issuance->fresh()->fee);
        $this->assertSame(900, app(PartnerProgramme::class)
            ->recordIssuance($this->partner, 'card', 'azure', 'Another Client')->fee);
    }

    public function test_voiding_an_issuance_leaves_the_row_and_stops_the_charge(): void
    {
        $issuance = app(PartnerProgramme::class)->recordIssuance($this->partner, 'card', 'azure', 'Garage Akwa');

        $issuance->void('Printed on the wrong stock');

        $this->assertSame('void', $issuance->fresh()->status);
        $this->assertSame(0, app(PartnerLedger::class)->fees($this->partner));
        $this->assertDatabaseHas('card_issuances', ['id' => $issuance->id]);
    }

    // ───────────────────────────────────────────────────── ledger ──

    public function test_the_balance_is_commission_less_fees_and_withdrawals(): void
    {
        Notification::fake();

        $business = $this->makeCompany('Boulangerie Nkolbisson');
        app(PartnerProgramme::class)->attribute($business, $this->partner->partnerCode());
        app(PartnerProgramme::class)->creditCommission($this->settledPayment($business, 21000)); // +2,100

        app(PartnerProgramme::class)->recordIssuance($this->partner, 'card', 'azure', 'A');       // −500
        app(PartnerProgramme::class)->recordIssuance($this->partner, 'card', 'azure', 'B');       // −500

        PartnerPayout::create(['amount' => 600, 'status' => 'paid', 'currency' => 'XAF']);        // −600

        $this->assertSame(500, app(PartnerLedger::class)->balance($this->partner));
    }

    /**
     * A payout still waiting to be sent already reduces the balance, or the
     * same money could be requested again while the first is in flight.
     */
    public function test_a_requested_payout_is_held_against_the_balance(): void
    {
        Notification::fake();

        $business = $this->makeCompany('Boulangerie Nkolbisson');
        app(PartnerProgramme::class)->attribute($business, $this->partner->partnerCode());
        app(PartnerProgramme::class)->creditCommission($this->settledPayment($business, 21000));

        PartnerPayout::create(['amount' => 2000, 'status' => 'requested', 'currency' => 'XAF']);

        $this->assertSame(100, app(PartnerLedger::class)->balance($this->partner));
    }

    public function test_a_partner_who_only_prints_owes_the_platform(): void
    {
        app(PartnerProgramme::class)->recordIssuance($this->partner, 'card', 'azure', 'A');
        app(PartnerProgramme::class)->recordIssuance($this->partner, 'card', 'azure', 'B');

        $this->assertSame(-1000, app(PartnerLedger::class)->balance($this->partner));
        $this->assertFalse(app(PartnerLedger::class)->canRequestPayout($this->partner));
    }

    // ──────────────────────────────────────────────────── tenancy ──

    public function test_one_partner_never_sees_another_partners_clients(): void
    {
        PartnerClient::create(['name' => 'Mine']);

        $rivalOwner = User::factory()->create();
        $rival = $this->makeCompany('Rival Secretariat', 'secretariat', $rivalOwner);
        $this->joinCompany($rival, $rivalOwner, Role::OWNER);

        app(CurrentCompany::class)->as($rival, function () {
            PartnerClient::create(['name' => 'Theirs']);

            $this->assertSame(['Theirs'], PartnerClient::query()->pluck('name')->all());
        });

        $this->assertSame(['Mine'], PartnerClient::query()->pluck('name')->all());
    }

    public function test_commission_rows_are_scoped_to_the_partner_that_earned_them(): void
    {
        Notification::fake();

        $business = $this->makeCompany('Boulangerie Nkolbisson');
        app(PartnerProgramme::class)->attribute($business, $this->partner->partnerCode());
        app(PartnerProgramme::class)->creditCommission($this->settledPayment($business));

        $rivalOwner = User::factory()->create();
        $rival = $this->makeCompany('Rival Secretariat', 'secretariat', $rivalOwner);
        $this->joinCompany($rival, $rivalOwner, Role::OWNER);

        app(CurrentCompany::class)->as($rival, function () {
            $this->assertSame(0, PartnerCommission::query()->count());
        });
    }

    // ─────────────────────────────────────────────────── the gate ──

    public function test_a_plain_business_cannot_reach_the_partner_pages(): void
    {
        $owner = User::factory()->create();
        $plain = $this->makeCompany('Just A Shop', 'business', $owner);
        $this->joinCompany($plain, $owner, Role::OWNER);
        $owner->forceFill(['current_company_id' => $plain->id])->save();

        // Even the Owner, whose role grants everything: the programme is a
        // property of the account, not of the person.
        $this->actingAs($owner)->get(route('partners.clients'))->assertForbidden();
        $this->actingAs($owner)->get(route('partners.earnings'))->assertForbidden();
    }

    public function test_a_secretariat_owner_can_reach_them(): void
    {
        $this->partnerOwner->forceFill(['current_company_id' => $this->partner->id])->save();

        $this->actingAs($this->partnerOwner)->get(route('partners.clients'))->assertOk();
        $this->actingAs($this->partnerOwner)->get(route('partners.earnings'))->assertOk();
    }

    // ──────────────────────────────────────────────── the screens ──

    public function test_the_client_book_adds_a_client_with_an_invite_token(): void
    {
        Livewire::actingAs($this->partnerOwner)
            ->test(Clients::class)
            ->call('startAdding')
            ->set('name', 'Boulangerie Nkolbisson')
            ->set('contactName', 'Marie Nkolo')
            ->call('save')
            ->assertHasNoErrors();

        $client = PartnerClient::where('name', 'Boulangerie Nkolbisson')->firstOrFail();

        $this->assertNotEmpty($client->invite_token);
        $this->assertStringContainsString($client->invite_token, $client->inviteUrl());
    }

    public function test_a_client_needs_a_name(): void
    {
        Livewire::actingAs($this->partnerOwner)
            ->test(Clients::class)
            ->call('startAdding')
            ->set('name', '')
            ->call('save')
            ->assertHasErrors('name');
    }

    public function test_issuing_from_the_client_page_charges_and_offers_the_print_sheet(): void
    {
        $client = PartnerClient::create(['name' => 'Garage Akwa']);

        Livewire::actingAs($this->partnerOwner)
            ->test(ClientShow::class, ['client' => $client])
            ->set('holderName', 'Paul Etoa')
            ->call('selectDesign', 'azure')
            ->call('startIssue')
            ->call('issue')
            ->assertHasNoErrors()
            ->assertSet('confirming', false);

        $this->assertSame(1, CardIssuance::query()->count());
        $this->assertSame(500, app(PartnerLedger::class)->fees($this->partner));
    }

    public function test_the_print_sheet_renders_the_client_not_the_partner(): void
    {
        $client = PartnerClient::create(['name' => 'Garage Akwa', 'contact_name' => 'Paul Etoa']);
        $this->partnerOwner->forceFill(['current_company_id' => $this->partner->id])->save();

        $this->actingAs($this->partnerOwner)
            ->get(route('partners.clients.print', ['client' => $client, 'asset' => 'card', 'design' => 'azure']))
            ->assertOk()
            ->assertSee('Garage Akwa', false)
            ->assertDontSee('Secretariat Bonamoussadi', false);
    }

    /**
     * The client page frames this sheet as its live preview. Left off the
     * self-embeddable list it inherits frame-ancestors 'none', and every design
     * tile renders as "refused to connect" — which looks like a broken template
     * rather than a header.
     */
    public function test_the_print_sheet_may_be_framed_by_this_app(): void
    {
        $client = PartnerClient::create(['name' => 'Garage Akwa']);
        $this->partnerOwner->forceFill(['current_company_id' => $this->partner->id])->save();

        $response = $this->actingAs($this->partnerOwner)
            ->get(route('partners.clients.print', ['client' => $client, 'asset' => 'card']));

        $this->assertStringContainsString(
            "frame-ancestors 'self'",
            $response->headers->get('Content-Security-Policy') ?? ''
        );
    }

    public function test_one_partner_cannot_print_another_partners_client(): void
    {
        $rivalOwner = User::factory()->create();
        $rival = $this->makeCompany('Rival Secretariat', 'secretariat', $rivalOwner);
        $this->joinCompany($rival, $rivalOwner, Role::OWNER);
        $rivalOwner->forceFill(['current_company_id' => $rival->id])->save();

        $mine = PartnerClient::create(['name' => 'Mine']);

        $this->actingAs($rivalOwner)
            ->get(route('partners.clients.print', ['client' => $mine, 'asset' => 'card']))
            ->assertNotFound();
    }

    public function test_a_payout_below_the_minimum_is_refused(): void
    {
        Livewire::actingAs($this->partnerOwner)
            ->test(Earnings::class)
            ->call('startRequest')
            ->set('destination', '+237670416238')
            ->call('requestPayout')
            ->assertHasErrors('destination');

        $this->assertSame(0, PartnerPayout::query()->count());
    }

    public function test_a_payout_request_takes_the_balance_from_the_ledger(): void
    {
        Notification::fake();

        $business = $this->makeCompany('Boulangerie Nkolbisson');
        app(PartnerProgramme::class)->attribute($business, $this->partner->partnerCode());
        // 10% of 210,000 (annual Business plan) = 21,000, over the minimum.
        app(PartnerProgramme::class)->creditCommission($this->settledPayment($business, 210000));

        Livewire::actingAs($this->partnerOwner)
            ->test(Earnings::class)
            ->call('startRequest')
            ->set('method', 'mtn')
            ->set('destination', '+237670416238')
            ->call('requestPayout')
            ->assertHasNoErrors();

        $payout = PartnerPayout::query()->firstOrFail();

        $this->assertSame(21000, $payout->amount);
        $this->assertSame('requested', $payout->status);
        $this->assertSame(0, app(PartnerLedger::class)->balance($this->partner));
    }
    // ────────────────────────────────────────── the platform admin ──

    protected function platformAdmin(string $role = 'admin'): PlatformAdmin
    {
        return PlatformAdmin::create([
            'name' => 'Platform Admin',
            'email' => $role.'@opes360.test',
            'password' => 'password',
            'role' => $role,
        ]);
    }

    /**
     * The summary is read by the admin screen, which has no current company.
     * Counting clients through $partner->partnerClients() would go through the
     * tenant scope, and that scope fails closed — so every partner would show
     * zero clients rather than raising anything.
     */
    public function test_the_ledger_summary_is_correct_from_outside_the_tenant(): void
    {
        PartnerClient::create(['name' => 'Boulangerie Nkolbisson']);
        PartnerClient::create(['name' => 'Garage Akwa']);
        app(PartnerProgramme::class)->recordIssuance($this->partner, 'card', 'azure', 'A');

        // No current company at all, as on the admin side.
        app(CurrentCompany::class)->set(null);

        $summary = app(PartnerLedger::class)->summary($this->partner);

        $this->assertSame(2, $summary['clients']);
        $this->assertSame(1, $summary['cards']);
        $this->assertSame(500, $summary['fees']);
    }

    public function test_the_admin_partner_page_lists_partners_and_what_they_are_owed(): void
    {
        Notification::fake();

        $business = $this->makeCompany('Boulangerie Nkolbisson');
        app(PartnerProgramme::class)->attribute($business, $this->partner->partnerCode());
        app(PartnerProgramme::class)->creditCommission($this->settledPayment($business, 210000));
        PartnerClient::create(['name' => 'Garage Akwa']);

        $this->actingAs($this->platformAdmin(), 'admin')
            ->get(route('admin.partners'))
            ->assertOk()
            ->assertSee('Secretariat Bonamoussadi')
            ->assertSee($this->partner->partnerCode())
            ->assertSee('21,000', false);   // 10% of 210,000
    }

    public function test_an_admin_can_mark_a_payout_sent_and_the_partner_is_told(): void
    {
        Notification::fake();

        $payout = PartnerPayout::create(['amount' => 12000, 'status' => 'requested', 'currency' => 'XAF', 'method' => 'mtn']);

        $this->actingAs($this->platformAdmin(), 'admin')
            ->post(route('admin.partners.payouts.settle', $payout), ['decision' => 'paid'])
            ->assertRedirect();

        $this->assertSame('paid', $payout->fresh()->status);
        $this->assertNotNull($payout->fresh()->settled_at);

        Notification::assertSentTo($this->partnerOwner, PartnerPayoutSettledNotification::class);
    }

    /** A rejected payout releases the balance it was holding. */
    public function test_rejecting_a_payout_returns_the_money_to_the_balance(): void
    {
        Notification::fake();

        $business = $this->makeCompany('Boulangerie Nkolbisson');
        app(PartnerProgramme::class)->attribute($business, $this->partner->partnerCode());
        app(PartnerProgramme::class)->creditCommission($this->settledPayment($business, 210000));

        $payout = PartnerPayout::create(['amount' => 21000, 'status' => 'requested', 'currency' => 'XAF']);
        $this->assertSame(0, app(PartnerLedger::class)->balance($this->partner));

        $this->actingAs($this->platformAdmin(), 'admin')
            ->post(route('admin.partners.payouts.settle', $payout), ['decision' => 'rejected', 'note' => 'Wrong number']);

        $this->assertSame(21000, app(PartnerLedger::class)->balance($this->partner));
    }

    /**
     * A payout marked paid has money behind it; settling it twice invites a
     * second transfer against the same balance.
     */
    public function test_a_settled_payout_cannot_be_settled_again(): void
    {
        Notification::fake();

        $payout = PartnerPayout::create(['amount' => 12000, 'status' => 'requested', 'currency' => 'XAF']);
        $admin = $this->platformAdmin();

        $this->actingAs($admin, 'admin')->post(route('admin.partners.payouts.settle', $payout), ['decision' => 'paid']);
        $this->actingAs($admin, 'admin')
            ->post(route('admin.partners.payouts.settle', $payout), ['decision' => 'rejected'])
            ->assertStatus(409);
    }

    /** Money leaving the business is an Admin action, not a Support one. */
    public function test_support_staff_cannot_settle_a_payout(): void
    {
        $payout = PartnerPayout::create(['amount' => 12000, 'status' => 'requested', 'currency' => 'XAF']);

        $this->actingAs($this->platformAdmin('support'), 'admin')
            ->post(route('admin.partners.payouts.settle', $payout), ['decision' => 'paid'])
            ->assertForbidden();

        $this->assertSame('requested', $payout->fresh()->status);
    }

    // ────────────────────────────────────────────────── letterhead ──

    /**
     * Cards and letterheads have separate design sets, and the sheet reads the
     * letterhead's choice off the company. A partner client has no company row,
     * so every client letterhead printed as 'rule' whatever was picked.
     */
    public function test_a_client_letterhead_honours_the_chosen_design(): void
    {
        $client = PartnerClient::create(['name' => 'Garage Akwa']);
        $this->partnerOwner->forceFill(['current_company_id' => $this->partner->id])->save();

        $this->actingAs($this->partnerOwner)
            ->get(route('partners.clients.print', ['client' => $client, 'asset' => 'letterhead', 'design' => 'crest']))
            ->assertOk()
            ->assertSee('lh-crest', false);
    }

    public function test_voiding_a_charge_from_the_client_page_leaves_the_row(): void
    {
        $client = PartnerClient::create(['name' => 'Garage Akwa']);
        $issuance = app(PartnerProgramme::class)
            ->recordIssuance($this->partner, 'card', 'azure', 'Garage Akwa', $client);

        Livewire::actingAs($this->partnerOwner)
            ->test(ClientShow::class, ['client' => $client])
            ->call('voidIssuance', $issuance->id, 'Printed on the wrong stock');

        $this->assertSame('void', $issuance->fresh()->status);
        $this->assertSame(0, app(PartnerLedger::class)->fees($this->partner));
    }

    /**
     * The production shape of the admin side: no current company at all.
     *
     * PartnerPayout is tenant-scoped like everything else a company owns, and
     * that scope fails closed. Route-model binding therefore resolved nothing
     * for the admin guard and every settle button answered 404 on a payout that
     * plainly existed — a fault the earlier tests could not see, because their
     * setUp had left a company set.
     */
    public function test_an_admin_settles_a_payout_with_no_tenant_context(): void
    {
        Notification::fake();

        $payout = PartnerPayout::create(['amount' => 12000, 'status' => 'requested', 'currency' => 'XAF']);

        app(CurrentCompany::class)->set(null);

        $this->actingAs($this->platformAdmin(), 'admin')
            ->post(route('admin.partners.payouts.settle', $payout), ['decision' => 'paid'])
            ->assertRedirect();

        $this->assertSame('paid', $payout->fresh()->status);
    }

    public function test_the_admin_partner_list_renders_with_no_tenant_context(): void
    {
        PartnerClient::create(['name' => 'Boulangerie Nkolbisson']);

        app(CurrentCompany::class)->set(null);

        $this->actingAs($this->platformAdmin(), 'admin')
            ->get(route('admin.partners'))
            ->assertOk()
            // The client count comes from the ledger, which must not read zero
            // just because there is no tenant in scope.
            ->assertSee('Secretariat Bonamoussadi');
    }

    public function test_settling_a_payout_that_does_not_exist_is_a_404_not_a_crash(): void
    {
        $this->actingAs($this->platformAdmin(), 'admin')
            ->post(route('admin.partners.payouts.settle', '01no-such-payout'), ['decision' => 'paid'])
            ->assertNotFound();
    }
}
