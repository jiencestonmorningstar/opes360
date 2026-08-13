<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyUserPermission;
use App\Models\Contact;
use App\Models\Event;
use App\Models\Form;
use App\Models\FormResponse;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Models\VerificationToken;
use App\Services\LoyaltyLedger;
use App\Support\CurrentCompany;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Events, forms and loyalty over the token API.
 *
 * These three modules already had screens, services and a permission group
 * each, so what is worth asserting is not that a controller returns JSON but
 * that the API arrives at the same guards the screens do: the oversell lock
 * inside TicketSeller, the separate `forms.responses` grant, the row-locked
 * sufficiency check inside LoyaltyLedger — and that the tenant boundary and
 * the token scopes hold in front of all of it.
 */
class EngagementApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->owner = User::factory()->create();
        $this->company = $this->makeCompany($this->owner);

        $this->joinCompany($this->company, $this->owner, Role::OWNER);
        $this->owner->forceFill(['current_company_id' => $this->company->id])->save();

        app(CurrentCompany::class)->set($this->company);

        Sanctum::actingAs($this->owner, ['*']);
    }

    /** Events and loyalty are both `business`-plan modules; forms is `growth`. */
    protected function makeCompany(User $owner): Company
    {
        return Company::create([
            'slug' => 'acme-'.Str::lower(Str::random(6)),
            'name' => 'Acme Sarl',
            'owner_id' => $owner->id,
            'currency' => 'XAF',
            'plan' => 'business',
            'account_type' => 'active',
            'loyalty_enabled' => true,
            'loyalty_points_per_amount' => 100,
            'loyalty_point_value' => 1,
        ]);
    }

    /** A published event starting next month, with one limited ticket type. */
    protected function publishedEvent(?int $quantity = 50, float $price = 5000): Event
    {
        $event = Event::create([
            'company_id' => $this->company->id,
            'title' => 'Concert de Noël',
            'venue' => 'Palais des Sports',
            'starts_at' => now()->addMonth(),
            'status' => 'published',
            'share_token' => Event::newShareToken(),
        ]);

        TicketType::create([
            'company_id' => $this->company->id,
            'event_id' => $event->id,
            'name' => 'Standard',
            'price' => $price,
            'quantity' => $quantity,
        ]);

        return $event->fresh();
    }

    protected function actAs(string $role, array $abilities = ['*']): User
    {
        $user = User::factory()->create();
        $this->joinCompany($this->company, $user, $role);
        $user->forceFill(['current_company_id' => $this->company->id])->save();

        Sanctum::actingAs($user, $abilities);

        return $user;
    }

    // ── Events: reading ──────────────────────────────────────────────────

    public function test_events_can_be_listed_and_read(): void
    {
        $event = $this->publishedEvent();

        $this->getJson('/api/v1/events')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Concert de Noël')
            ->assertJsonPath('data.0.selling', true);

        $this->getJson("/api/v1/events/{$event->id}")
            ->assertOk()
            ->assertJsonPath('data.ticket_types.0.name', 'Standard')
            ->assertJsonPath('data.ticket_types.0.remaining', 50);
    }

    /** Null remaining means unlimited, not zero and not a large number. */
    public function test_an_unlimited_ticket_type_reports_no_remaining_count(): void
    {
        $event = $this->publishedEvent(quantity: null);

        $this->getJson("/api/v1/events/{$event->id}/ticket-types")
            ->assertOk()
            ->assertJsonPath('data.0.remaining', null)
            ->assertJsonPath('data.0.sold_out', false);
    }

    // ── Events: selling ──────────────────────────────────────────────────

    public function test_selling_a_ticket_issues_it_with_a_verification_token(): void
    {
        $event = $this->publishedEvent();
        $type = $event->ticketTypes()->first();

        $response = $this->postJson("/api/v1/events/{$event->id}/tickets", [
            'buyer_name' => 'Marie Ngo',
            'buyer_email' => 'marie@example.test',
            'quantities' => [$type->id => 2],
        ])->assertCreated();

        // Two rows, not one row with a quantity: each admits one person
        // separately and each carries its own QR.
        $this->assertCount(2, $response->json('data'));
        $this->assertNotNull($response->json('data.0.verification_token'));
        $this->assertNotSame(
            $response->json('data.0.serial'),
            $response->json('data.1.serial'),
        );

        // Availability came off the type, which is the oversell guard.
        $this->assertSame(2, (int) $type->fresh()->sold);
    }

    /**
     * The seat count is enforced inside TicketSeller under a row lock, and the
     * API must not have found a way past it.
     */
    public function test_selling_more_than_remain_is_refused(): void
    {
        $event = $this->publishedEvent(quantity: 1);
        $type = $event->ticketTypes()->first();

        $this->postJson("/api/v1/events/{$event->id}/tickets", [
            'buyer_name' => 'Marie Ngo',
            'buyer_phone' => '+237670000000',
            'quantities' => [$type->id => 2],
        ])->assertStatus(422);

        $this->assertSame(0, $event->tickets()->count());
        $this->assertSame(0, (int) $type->fresh()->sold);
    }

    public function test_a_sold_out_type_cannot_be_sold_again(): void
    {
        $event = $this->publishedEvent(quantity: 1);
        $type = $event->ticketTypes()->first();

        $this->postJson("/api/v1/events/{$event->id}/tickets", [
            'buyer_name' => 'First',
            'buyer_phone' => '+237670000001',
            'quantities' => [$type->id => 1],
        ])->assertCreated();

        $this->postJson("/api/v1/events/{$event->id}/tickets", [
            'buyer_name' => 'Second',
            'buyer_phone' => '+237670000002',
            'quantities' => [$type->id => 1],
        ])->assertStatus(422);

        $this->assertSame(1, $event->tickets()->count());
    }

    /** Sales stop themselves once the event has started — the service says so. */
    public function test_a_draft_event_cannot_sell(): void
    {
        $event = $this->publishedEvent();
        $event->update(['status' => 'draft']);
        $type = $event->ticketTypes()->first();

        $this->postJson("/api/v1/events/{$event->id}/tickets", [
            'buyer_name' => 'Marie Ngo',
            'buyer_phone' => '+237670000000',
            'quantities' => [$type->id => 1],
        ])->assertStatus(422);
    }

    public function test_a_buyer_with_neither_email_nor_phone_is_refused(): void
    {
        $event = $this->publishedEvent();
        $type = $event->ticketTypes()->first();

        $this->postJson("/api/v1/events/{$event->id}/tickets", [
            'buyer_name' => 'Marie Ngo',
            'quantities' => [$type->id => 1],
        ])->assertStatus(422)->assertJsonValidationErrors('buyer_email');
    }

    // ── Events: the door ─────────────────────────────────────────────────

    public function test_a_ticket_can_be_checked_in_once(): void
    {
        $event = $this->publishedEvent();
        $type = $event->ticketTypes()->first();

        $id = $this->postJson("/api/v1/events/{$event->id}/tickets", [
            'buyer_name' => 'Marie Ngo',
            'buyer_phone' => '+237670000000',
            'quantities' => [$type->id => 1],
        ])->json('data.0.id');

        $this->postJson("/api/v1/events/{$event->id}/tickets/{$id}/check-in")
            ->assertOk()
            ->assertJsonPath('data.status', 'checked_in');

        // A door needs to be told the second time, not silently obliged.
        $this->postJson("/api/v1/events/{$event->id}/tickets/{$id}/check-in")
            ->assertStatus(422);
    }

    public function test_a_voided_ticket_is_not_admitted(): void
    {
        $event = $this->publishedEvent();
        $type = $event->ticketTypes()->first();

        $ticket = Ticket::create([
            'company_id' => $this->company->id,
            'event_id' => $event->id,
            'ticket_type_id' => $type->id,
            'serial' => Ticket::newSerial($this->company->id),
            'buyer_name' => 'Marie Ngo',
            'price' => 5000,
            'status' => 'void',
        ]);

        $this->postJson("/api/v1/events/{$event->id}/tickets/{$ticket->id}/check-in")
            ->assertStatus(422);
    }

    /** A serial from another night must not open this one's door. */
    public function test_a_ticket_from_another_event_cannot_be_checked_in_here(): void
    {
        $one = $this->publishedEvent();
        $two = $this->publishedEvent();
        $type = $one->ticketTypes()->first();

        $ticket = Ticket::create([
            'company_id' => $this->company->id,
            'event_id' => $one->id,
            'ticket_type_id' => $type->id,
            'serial' => Ticket::newSerial($this->company->id),
            'buyer_name' => 'Marie Ngo',
            'price' => 5000,
            'status' => 'issued',
        ]);

        $this->postJson("/api/v1/events/{$two->id}/tickets/{$ticket->id}/check-in")
            ->assertNotFound();

        $this->assertSame('issued', $ticket->fresh()->status);
    }

    /** Deleting an issued ticket is not offered: a vanished serial cannot be checked. */
    public function test_there_is_no_route_to_delete_an_issued_ticket(): void
    {
        $event = $this->publishedEvent();
        $type = $event->ticketTypes()->first();

        $id = $this->postJson("/api/v1/events/{$event->id}/tickets", [
            'buyer_name' => 'Marie Ngo',
            'buyer_phone' => '+237670000000',
            'quantities' => [$type->id => 1],
        ])->json('data.0.id');

        // No route matches at all, so this is a 404 rather than a 405.
        $this->deleteJson("/api/v1/events/{$event->id}/tickets/{$id}")->assertNotFound();

        $this->assertNotNull(Ticket::find($id));
    }

    // ── Forms ────────────────────────────────────────────────────────────

    public function test_forms_and_their_responses_can_be_read(): void
    {
        $form = Form::create([
            'company_id' => $this->company->id,
            'title' => 'Satisfaction client',
            'status' => 'open',
            'share_token' => Form::newShareToken(),
            'fields' => [['id' => 'f1', 'type' => 'text', 'label' => 'Votre nom']],
        ]);

        FormResponse::create([
            'company_id' => $this->company->id,
            'form_id' => $form->id,
            'answers' => ['f1' => 'Marie Ngo'],
        ]);

        $this->getJson('/api/v1/forms')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Satisfaction client')
            ->assertJsonPath('data.0.response_count', 1);

        $this->getJson("/api/v1/forms/{$form->id}")
            ->assertOk()
            ->assertJsonPath('data.fields.0.id', 'f1');

        // Answers stay keyed by field id — the whole point of the storage
        // format is that renaming a field never rewrites an old submission.
        $this->getJson("/api/v1/forms/{$form->id}/responses")
            ->assertOk()
            ->assertJsonPath('data.0.answers.f1', 'Marie Ngo');
    }

    /** Creating a form over HTTP is not offered; the builder is the tool for it. */
    public function test_a_form_cannot_be_created_over_the_api(): void
    {
        $this->postJson('/api/v1/forms', ['title' => 'Nouveau'])->assertStatus(405);
    }

    // ── Loyalty ──────────────────────────────────────────────────────────

    protected function customerWithPoints(int $points = 500): Contact
    {
        $contact = Contact::create(['type' => 'customer', 'name' => 'Boulangerie Nkolbisson']);

        if ($points > 0) {
            app(LoyaltyLedger::class)->adjust($contact, $points, 'Solde de départ', $this->owner);
        }

        return $contact->fresh();
    }

    public function test_a_balance_and_its_ledger_can_be_read(): void
    {
        $contact = $this->customerWithPoints(500);

        $this->getJson("/api/v1/loyalty/contacts/{$contact->id}")
            ->assertOk()
            ->assertJsonPath('data.points', 500)
            ->assertJsonPath('data.value', fn ($v) => (float) $v === 500.0)
            ->assertJsonPath('data.program_enabled', true);

        $this->getJson("/api/v1/loyalty/contacts/{$contact->id}/transactions")
            ->assertOk()
            ->assertJsonPath('data.0.type', 'adjust')
            ->assertJsonPath('data.0.balance_after', 500);
    }

    public function test_redeeming_points_writes_a_ledger_row_and_moves_the_balance(): void
    {
        $contact = $this->customerWithPoints(500);

        $this->postJson("/api/v1/loyalty/contacts/{$contact->id}/redeem", [
            'points' => 200,
            'note' => 'Remise en caisse',
        ])->assertCreated()
            ->assertJsonPath('data.points', -200)
            ->assertJsonPath('data.balance_after', 300);

        $this->assertSame(300, (int) $contact->fresh()->loyalty_points);
    }

    /**
     * Refused, not clamped. Clamping the loser of a race to zero would hand
     * out the reward twice and leave a ledger balancing to a number nobody
     * ever had — the service's own docblock is explicit about this.
     */
    public function test_redeeming_more_than_the_balance_is_refused(): void
    {
        $contact = $this->customerWithPoints(100);

        $this->postJson("/api/v1/loyalty/contacts/{$contact->id}/redeem", ['points' => 500])
            ->assertStatus(422);

        $this->assertSame(100, (int) $contact->fresh()->loyalty_points);
        $this->assertSame(1, $contact->loyaltyTransactions()->count());
    }

    public function test_redeeming_zero_or_fewer_points_is_refused(): void
    {
        $contact = $this->customerWithPoints(100);

        $this->postJson("/api/v1/loyalty/contacts/{$contact->id}/redeem", ['points' => 0])
            ->assertStatus(422)->assertJsonValidationErrors('points');
    }

    /** Points are earned by spending, never minted by a token. */
    public function test_there_is_no_route_to_award_or_adjust_points(): void
    {
        $contact = $this->customerWithPoints(100);

        $this->postJson("/api/v1/loyalty/contacts/{$contact->id}/adjust", ['points' => 10000])
            ->assertNotFound();
        $this->postJson("/api/v1/loyalty/contacts/{$contact->id}/earn", ['points' => 10000])
            ->assertNotFound();

        $this->assertSame(100, (int) $contact->fresh()->loyalty_points);
    }

    // ── Token scopes ─────────────────────────────────────────────────────

    /** Read is read: a dashboard token cannot sell a seat. */
    public function test_a_read_token_cannot_sell_a_ticket(): void
    {
        $event = $this->publishedEvent();
        $type = $event->ticketTypes()->first();

        Sanctum::actingAs($this->owner, ['read']);

        $this->getJson('/api/v1/events')->assertOk();

        $this->postJson("/api/v1/events/{$event->id}/tickets", [
            'buyer_name' => 'Marie Ngo',
            'buyer_phone' => '+237670000000',
            'quantities' => [$type->id => 1],
        ])->assertForbidden();
    }

    /**
     * `write` is not `money`, and the two do not imply each other: a token
     * minted to scan at a door can admit people and cannot issue a ticket or
     * spend a customer's points.
     */
    public function test_a_write_token_can_scan_but_cannot_sell_or_redeem(): void
    {
        $event = $this->publishedEvent();
        $type = $event->ticketTypes()->first();
        $contact = $this->customerWithPoints(500);

        $id = $this->postJson("/api/v1/events/{$event->id}/tickets", [
            'buyer_name' => 'Marie Ngo',
            'buyer_phone' => '+237670000000',
            'quantities' => [$type->id => 1],
        ])->json('data.0.id');

        Sanctum::actingAs($this->owner, ['write']);

        $this->postJson("/api/v1/events/{$event->id}/tickets/{$id}/check-in")->assertOk();

        $this->postJson("/api/v1/events/{$event->id}/tickets", [
            'buyer_name' => 'Autre',
            'buyer_phone' => '+237670000009',
            'quantities' => [$type->id => 1],
        ])->assertForbidden();

        $this->postJson("/api/v1/loyalty/contacts/{$contact->id}/redeem", ['points' => 10])
            ->assertForbidden();
    }

    public function test_a_money_token_cannot_read_the_forms_it_does_not_need(): void
    {
        Sanctum::actingAs($this->owner, ['money']);

        $this->getJson('/api/v1/forms')->assertForbidden();
    }

    // ── Roles ────────────────────────────────────────────────────────────

    /**
     * A cashier holds `events.view` and `events.check-in` but not
     * `events.create`. Scanning at the door and issuing a seat are different
     * trusts, and the catalogue already says so.
     */
    public function test_a_cashier_can_scan_but_not_issue(): void
    {
        $event = $this->publishedEvent();
        $type = $event->ticketTypes()->first();

        $ticket = Ticket::create([
            'company_id' => $this->company->id,
            'event_id' => $event->id,
            'ticket_type_id' => $type->id,
            'serial' => Ticket::newSerial($this->company->id),
            'buyer_name' => 'Marie Ngo',
            'price' => 5000,
            'status' => 'issued',
        ]);

        $this->actAs('cashier');

        $this->postJson("/api/v1/events/{$event->id}/tickets/{$ticket->id}/check-in")->assertOk();

        $this->postJson("/api/v1/events/{$event->id}/tickets", [
            'buyer_name' => 'Autre',
            'buyer_phone' => '+237670000000',
            'quantities' => [$type->id => 1],
        ])->assertForbidden();
    }

    /** The cashier's grants contain no Forms group at all. */
    public function test_a_cashier_cannot_read_forms(): void
    {
        Form::create([
            'company_id' => $this->company->id,
            'title' => 'Satisfaction client',
            'status' => 'open',
            'share_token' => Form::newShareToken(),
            'fields' => [],
        ]);

        $this->actAs('cashier');

        $this->getJson('/api/v1/forms')->assertForbidden();
    }

    /**
     * Reading submissions is its own grant, separate from seeing that a form
     * exists — a form's existence is not its contents, and submissions are
     * other people's names and complaints.
     *
     * Every seeded role that can see forms happens to hold `forms.responses`
     * too, so the split is demonstrated the way a business would actually make
     * it: an explicit per-user revoke, which beats the role's grant.
     */
    public function test_reading_responses_needs_its_own_grant(): void
    {
        $form = Form::create([
            'company_id' => $this->company->id,
            'title' => 'Satisfaction client',
            'status' => 'open',
            'share_token' => Form::newShareToken(),
            'fields' => [['id' => 'f1', 'type' => 'text', 'label' => 'Nom']],
        ]);

        $user = $this->actAs('accountant');
        $this->getJson("/api/v1/forms/{$form->id}/responses")->assertOk();

        CompanyUserPermission::create([
            'company_id' => $this->company->id,
            'user_id' => $user->id,
            'permission_id' => Permission::where('slug', 'forms.responses')->value('id'),
            'granted' => false,
        ]);
        $user->forgetRoleCache();

        $this->getJson("/api/v1/forms/{$form->id}")->assertOk();
        $this->getJson("/api/v1/forms/{$form->id}/responses")->assertForbidden();
    }

    /** An accountant may read a balance; spending it is the till's job. */
    public function test_an_accountant_can_read_points_but_not_redeem_them(): void
    {
        $contact = $this->customerWithPoints(500);

        $this->actAs('accountant');

        $this->getJson("/api/v1/loyalty/contacts/{$contact->id}")->assertOk();

        $this->postJson("/api/v1/loyalty/contacts/{$contact->id}/redeem", ['points' => 10])
            ->assertForbidden();

        $this->assertSame(500, (int) $contact->fresh()->loyalty_points);
    }

    // ── The tenant boundary ──────────────────────────────────────────────

    /**
     * Another business's records do not 403, they 404 — for this token they do
     * not exist, which is the same answer the web side gives.
     */
    public function test_another_companys_records_are_not_found(): void
    {
        $stranger = User::factory()->create();
        $other = $this->makeCompany($stranger);

        $event = Event::create([
            'company_id' => $other->id,
            'title' => 'Autre concert',
            'starts_at' => now()->addMonth(),
            'status' => 'published',
            'share_token' => Event::newShareToken(),
        ]);

        $type = TicketType::create([
            'company_id' => $other->id,
            'event_id' => $event->id,
            'name' => 'Standard',
            'price' => 1000,
            'quantity' => 10,
        ]);

        $form = Form::create([
            'company_id' => $other->id,
            'title' => 'Leur formulaire',
            'status' => 'open',
            'share_token' => Form::newShareToken(),
            'fields' => [],
        ]);

        $contact = Contact::withoutGlobalScopes()->create([
            'company_id' => $other->id,
            'type' => 'customer',
            'name' => 'Leur client',
            'loyalty_points' => 900,
        ]);

        $this->getJson("/api/v1/events/{$event->id}")->assertNotFound();
        $this->getJson("/api/v1/events/{$event->id}/tickets")->assertNotFound();
        $this->getJson("/api/v1/forms/{$form->id}")->assertNotFound();
        $this->getJson("/api/v1/forms/{$form->id}/responses")->assertNotFound();
        $this->getJson("/api/v1/loyalty/contacts/{$contact->id}")->assertNotFound();

        $this->postJson("/api/v1/events/{$event->id}/tickets", [
            'buyer_name' => 'Marie Ngo',
            'buyer_phone' => '+237670000000',
            'quantities' => [$type->id => 1],
        ])->assertNotFound();

        $this->postJson("/api/v1/loyalty/contacts/{$contact->id}/redeem", ['points' => 100])
            ->assertNotFound();

        // Nothing leaked, and nothing moved.
        $this->assertSame(0, $event->tickets()->withoutGlobalScopes()->count());
        $this->assertSame(900, (int) $contact->fresh()->loyalty_points);
    }

    /** Lists never reach across the boundary either. */
    public function test_lists_only_show_this_companys_records(): void
    {
        $stranger = User::factory()->create();
        $other = $this->makeCompany($stranger);

        Event::create([
            'company_id' => $other->id,
            'title' => 'Autre concert',
            'starts_at' => now()->addMonth(),
            'status' => 'published',
            'share_token' => Event::newShareToken(),
        ]);

        $this->publishedEvent();

        $titles = collect($this->getJson('/api/v1/events')->assertOk()->json('data'))
            ->pluck('title');

        $this->assertSame(['Concert de Noël'], $titles->all());
    }

    /**
     * A sale mints the verification token the QR is built from. Without it a
     * ticket printed by a caller would be one the door cannot check, which is
     * the entire reason tickets are rows rather than a quantity.
     */
    public function test_a_sale_creates_a_verification_token_row(): void
    {
        $event = $this->publishedEvent();
        $type = $event->ticketTypes()->first();

        $before = VerificationToken::query()->count();

        $this->postJson("/api/v1/events/{$event->id}/tickets", [
            'buyer_name' => 'Marie Ngo',
            'buyer_phone' => '+237670000000',
            'quantities' => [$type->id => 1],
        ])->assertCreated();

        $this->assertSame($before + 1, VerificationToken::query()->count());
    }
}
