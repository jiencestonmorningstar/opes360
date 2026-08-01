<?php

namespace Tests\Feature;

use App\Livewire\Business\Companies;
use App\Livewire\Reports\Index as ReportsIndex;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Event;
use App\Models\Form;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\User;
use App\Models\VerificationToken;
use App\Services\LoyaltyLedger;
use App\Services\TicketSeller;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * Regressions for the platform-wide audit.
 *
 * Each test here stands for a specific defect that shipped and was fixed, so
 * the name says what used to be true rather than what the feature does.
 */
class SecurityAuditRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();

        $this->company = Company::create([
            'slug' => 'acme',
            'name' => 'Acme Ltd',
            'owner_id' => $this->owner->id,
            'currency' => 'XAF',
            'account_type' => 'active',
            'plan' => 'business',
            'loyalty_enabled' => true,
        ]);

        $this->joinCompany($this->company, $this->owner, Role::OWNER);
        $this->owner->forceFill(['current_company_id' => $this->company->id])->save();
        app(CurrentCompany::class)->set($this->company);
    }

    protected function member(string $role): User
    {
        $user = User::factory()->create();
        $this->joinCompany($this->company, $user, $role);
        $user->forceFill(['current_company_id' => $this->company->id])->save();

        return $user;
    }

    /*
     * Loyalty: the balance check used to sit outside the row lock, and record()
     * clamped a negative result to zero — so two concurrent redemptions of the
     * last 100 points both succeeded and the customer spent 200.
     */

    public function test_a_redemption_larger_than_the_balance_is_refused_not_clamped_to_zero(): void
    {
        $contact = Contact::create(['name' => 'Ada Obi']);
        $ledger = app(LoyaltyLedger::class);

        $ledger->adjust($contact, 100, 'Opening balance', $this->owner);

        // The stale-read case the pre-check cannot catch: a second caller
        // holding a copy of the contact from before the first redemption.
        $stale = Contact::find($contact->id);
        $ledger->redeem($contact->fresh(), 100, null, $this->owner);

        $this->expectException(RuntimeException::class);

        try {
            $ledger->redeem($stale, 100, null, $this->owner);
        } finally {
            // The opening +100 and the one legitimate -100, and nothing else:
            // the refused redemption must leave no ledger row behind, and the
            // balance must be zero rather than a clamped -100.
            $this->assertSame(0, (int) $contact->fresh()->loyalty_points);
            $this->assertSame(2, DB::table('loyalty_transactions')->count());
            $this->assertSame(0, (int) DB::table('loyalty_transactions')->sum('points'));
        }
    }

    public function test_an_adjustment_below_zero_is_still_refused_under_the_lock(): void
    {
        $contact = Contact::create(['name' => 'Ada Obi']);
        $ledger = app(LoyaltyLedger::class);

        $ledger->adjust($contact, 50, 'Opening balance', $this->owner);

        $stale = Contact::find($contact->id);
        $ledger->redeem($contact->fresh(), 50, null, $this->owner);

        $this->expectException(RuntimeException::class);
        $ledger->adjust($stale, -50, 'Correction', $this->owner);
    }

    /*
     * Reports: reports.export was seeded, gated and never checked. The route
     * only requires reports.view, which Sales Officer and Read Only both hold.
     */

    public function test_a_role_with_view_but_not_export_cannot_download_the_ledger(): void
    {
        foreach ([Role::SALES_OFFICER, Role::READ_ONLY] as $role) {
            $user = $this->member($role);

            Livewire::actingAs($user)
                ->test(ReportsIndex::class)
                ->call('exportCsv')
                ->assertForbidden();
        }
    }

    public function test_a_role_with_export_can_still_download_the_ledger(): void
    {
        $accountant = $this->member(Role::ACCOUNTANT);

        Livewire::actingAs($accountant)
            ->test(ReportsIndex::class)
            ->call('exportCsv')
            ->assertOk();
    }

    /*
     * Entitlements: Gate::before returned true for the Owner, which satisfied
     * the whole check and skipped the policy — including the plan test inside
     * CompanyScopedPolicy::allows(). Gate::before's own plan test never fired
     * for model-backed checks, because the ability there is a bare method name
     * like "checkIn" with no module prefix to match on.
     */

    public function test_an_owner_cannot_use_a_module_their_plan_excludes(): void
    {
        $this->company->forceFill(['plan' => 'basic'])->save();

        $event = Event::create([
            'title' => 'Launch Night',
            'starts_at' => now()->addWeek(),
            'status' => 'published',
            'share_token' => Event::newShareToken(),
        ]);

        $type = $event->ticketTypes()->create([
            'company_id' => $this->company->id,
            'name' => 'General',
            'price' => 10,
            'quantity' => 5,
        ]);

        $ticket = app(TicketSeller::class)->sell($event, [$type->id => 1], 'Ada Obi', 'ada@example.com', null)[0];

        // Events is a Business-plan module; Basic must not reach it, owner or not.
        $this->actingAs($this->owner)
            ->post('/tickets/'.$ticket->id.'/check-in')
            ->assertForbidden();

        $this->assertFalse($ticket->fresh()->isCheckedIn());
    }

    public function test_an_owner_on_the_right_plan_is_still_allowed_through(): void
    {
        $event = Event::create([
            'title' => 'Launch Night',
            'starts_at' => now()->addWeek(),
            'status' => 'published',
            'share_token' => Event::newShareToken(),
        ]);

        $type = $event->ticketTypes()->create([
            'company_id' => $this->company->id,
            'name' => 'General',
            'price' => 10,
            'quantity' => 5,
        ]);

        $ticket = app(TicketSeller::class)->sell($event, [$type->id => 1], 'Ada Obi', 'ada@example.com', null)[0];

        $this->actingAs($this->owner)
            ->post('/tickets/'.$ticket->id.'/check-in')
            ->assertRedirect();

        $this->assertTrue($ticket->fresh()->isCheckedIn());
    }

    /*
     * Membership: belongsToCompany() and roleIn() ignored the pivot status that
     * every other reader filtered on, so a deactivated member kept their role.
     */

    public function test_a_deactivated_member_loses_their_role_and_their_access(): void
    {
        $member = $this->member(Role::ACCOUNTANT);

        $this->assertTrue($member->belongsToCompany($this->company));
        $this->assertNotNull($member->roleIn($this->company));

        $this->company->users()->updateExistingPivot($member->id, ['status' => 'suspended']);
        $member->refresh();

        $this->assertFalse($member->belongsToCompany($this->company));
        $this->assertNull($member->roleIn($this->company));
        $this->assertFalse($member->hasPermissionIn($this->company, 'sales.view'));
    }

    public function test_a_deactivated_member_cannot_switch_back_into_the_business(): void
    {
        $member = $this->member(Role::ACCOUNTANT);
        $this->company->users()->updateExistingPivot($member->id, ['status' => 'suspended']);

        Livewire::actingAs($member->fresh())
            ->test(Companies::class)
            ->call('switchTo', $this->company->id)
            ->assertHasErrors('switch');
    }

    /*
     * Suspension: only SetCurrentCompany consulted it, and that never runs for
     * a guest — so a suspended business kept trading in public.
     */

    public function test_a_suspended_business_stops_selling_tickets_and_collecting_responses(): void
    {
        $event = Event::create([
            'title' => 'Launch Night',
            'starts_at' => now()->addWeek(),
            'status' => 'published',
            'share_token' => Event::newShareToken(),
        ]);

        $event->ticketTypes()->create([
            'company_id' => $this->company->id,
            'name' => 'General',
            'price' => 10,
            'quantity' => 5,
        ]);

        $form = Form::create([
            'title' => 'Registration',
            'status' => 'open',
            'share_token' => Form::newShareToken(),
            'fields' => [
                ['id' => 'f-name', 'type' => 'short_text', 'label' => 'Name', 'required' => true, 'options' => []],
            ],
        ]);

        // Everything is reachable while the business is in good standing.
        $this->get('/e/'.$event->share_token)->assertOk();
        $this->get('/f/'.$form->share_token)->assertOk();
        $this->get('/business/'.$this->company->slug)->assertOk();

        $this->company->forceFill(['suspended_at' => now()])->save();

        $this->get('/e/'.$event->share_token)->assertNotFound();
        $this->get('/f/'.$form->share_token)->assertNotFound();
        $this->get('/business/'.$this->company->slug)->assertNotFound();
        $this->get('/business/'.$this->company->slug.'/vcard')->assertNotFound();

        $this->post('/f/'.$form->share_token, ['answers' => ['f-name' => 'Ada']])->assertNotFound();
        $this->assertSame(0, DB::table('form_responses')->count());
    }

    public function test_a_suspended_business_keeps_its_already_issued_documents_verifiable(): void
    {
        $token = VerificationToken::create([
            'company_id' => $this->company->id,
            'token' => VerificationToken::newToken(),
            'subject_type' => Company::class,
            'subject_id' => $this->company->id,
        ]);

        $this->company->forceFill(['suspended_at' => now()])->save();

        // The customer holding a printed receipt is not party to the dispute
        // that suspended the merchant, and must not lose the ability to check it.
        $this->get('/v/'.$token->token)->assertOk();
    }

    /*
     * Identifiers: every one of these columns is unique and none of the
     * generators checked, so a collision 500'd mid-transaction.
     */

    public function test_a_taken_ticket_serial_is_generated_around_rather_than_collided_with(): void
    {
        $event = Event::create([
            'title' => 'Launch Night',
            'starts_at' => now()->addWeek(),
            'status' => 'published',
            'share_token' => Event::newShareToken(),
        ]);

        $type = $event->ticketTypes()->create([
            'company_id' => $this->company->id,
            'name' => 'General',
            'price' => 10,
            'quantity' => 50,
        ]);

        $tickets = app(TicketSeller::class)->sell($event, [$type->id => 10], 'Ada Obi', 'ada@example.com', null);

        $serials = collect($tickets)->pluck('serial');
        $this->assertCount(10, $serials->unique(), 'Ticket serials collided within a single order.');

        // The serial is unique per company, so the generator has to consult the
        // company it is generating for rather than the current tenant scope.
        $this->assertNotSame(
            $tickets[0]->serial,
            Ticket::newSerial($this->company->id),
        );
    }

    public function test_generated_tokens_do_not_reuse_one_that_already_exists(): void
    {
        $existing = VerificationToken::create([
            'company_id' => $this->company->id,
            'token' => VerificationToken::newToken(),
            'subject_type' => Company::class,
            'subject_id' => $this->company->id,
        ]);

        for ($i = 0; $i < 20; $i++) {
            $this->assertNotSame($existing->token, VerificationToken::newToken());
        }
    }

    /*
     * Tickets page: the session key was replaced on every purchase, so a second
     * order left the buyer's first tickets unreachable.
     */

    public function test_a_second_order_does_not_lose_the_tickets_from_the_first(): void
    {
        $event = Event::create([
            'title' => 'Launch Night',
            'starts_at' => now()->addWeek(),
            'status' => 'published',
            'share_token' => Event::newShareToken(),
        ]);

        $type = $event->ticketTypes()->create([
            'company_id' => $this->company->id,
            'name' => 'General',
            'price' => 10,
            'quantity' => 50,
        ]);

        $payload = fn (int $n) => [
            'buyer_name' => 'Ada Obi',
            'buyer_email' => 'ada@example.com',
            'quantities' => [$type->id => $n],
        ];

        $this->post('/e/'.$event->share_token, $payload(1))->assertRedirect();
        $this->post('/e/'.$event->share_token, $payload(2))->assertRedirect();

        $this->get('/e/'.$event->share_token.'/tickets')
            ->assertOk()
            ->assertSee(Ticket::orderBy('created_at')->first()->serial);

        $this->assertCount(3, session('purchased_tickets'));
    }
}
