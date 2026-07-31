<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Event;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Services\SoldOutException;
use App\Services\TicketSeller;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Module 17 — Opes Events.
 *
 * The two things that must never fail: the same seat cannot be sold twice,
 * and the same ticket cannot admit two people.
 */
class EventsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->company = Company::create([
            'slug' => 'acme',
            'name' => 'Acme Ltd',
            'owner_id' => $this->user->id,
            'currency' => 'USD',
            'plan' => 'business',
        ]);

        $this->joinCompany($this->company, $this->user);
        $this->user->forceFill(['current_company_id' => $this->company->id])->save();
        app(CurrentCompany::class)->set($this->company);
    }

    protected function makeEvent(string $status = 'published', int $quantity = 5): array
    {
        $event = Event::create([
            'title' => 'Launch Night',
            'venue' => 'Landmark Centre',
            'starts_at' => now()->addWeek(),
            'status' => $status,
            'share_token' => Event::newShareToken(),
        ]);

        $type = $event->ticketTypes()->create([
            'company_id' => $this->company->id,
            'name' => 'General',
            'price' => 25,
            'quantity' => $quantity,
        ]);

        return [$event, $type];
    }

    public function test_event_pages_render_for_the_owner(): void
    {
        [$event] = $this->makeEvent();

        $this->actingAs($this->user)->get('/events')->assertOk()->assertSee('Launch Night');
        $this->actingAs($this->user)->get('/events/create')->assertOk()->assertSee('New event');
        $this->actingAs($this->user)->get('/events/'.$event->id)->assertOk()->assertSee('Ticket sales page');
        $this->actingAs($this->user)->get('/events/'.$event->id.'/edit')->assertOk()->assertSee('Edit event');

        $this->get('/e/'.$event->share_token)->assertOk()->assertSee('Get tickets');
    }

    public function test_public_page_sells_tickets_and_issues_qr_tokens(): void
    {
        [$event, $type] = $this->makeEvent();

        $this->post('/e/'.$event->share_token, [
            'buyer_name' => 'Ada Obi',
            'buyer_email' => 'ada@example.com',
            'quantities' => [$type->id => 2],
        ])->assertRedirect('/e/'.$event->share_token.'/tickets');

        $tickets = Ticket::withoutGlobalScopes()->get();

        $this->assertCount(2, $tickets);
        $this->assertSame(2, $type->fresh()->sold);

        foreach ($tickets as $ticket) {
            $this->assertNotNull($ticket->verification_token_id);
            $this->assertSame('25.00', $ticket->price);
            $this->assertSame($this->company->id, $ticket->company_id);
        }

        // The confirmation page renders from the session.
        $this->get('/e/'.$event->share_token.'/tickets')
            ->assertOk()
            ->assertSee($tickets[0]->serial);
    }

    public function test_oversell_is_blocked(): void
    {
        [$event, $type] = $this->makeEvent(quantity: 3);

        $seller = app(TicketSeller::class);
        $seller->sell($event, [$type->id => 2], 'First Buyer', 'a@example.com', null);

        $this->expectException(SoldOutException::class);
        $seller->sell($event, [$type->id => 2], 'Second Buyer', 'b@example.com', null);
    }

    public function test_a_draft_event_is_not_public(): void
    {
        [$event] = $this->makeEvent(status: 'draft');

        $this->get('/e/'.$event->share_token)->assertNotFound();
    }

    public function test_sales_stop_once_the_event_starts(): void
    {
        [$event, $type] = $this->makeEvent();
        $event->update(['starts_at' => now()->subHour()]);

        $this->get('/e/'.$event->share_token)->assertOk()->assertSee('sales have closed');

        $this->post('/e/'.$event->share_token, [
            'buyer_name' => 'Late Buyer',
            'buyer_email' => 'late@example.com',
            'quantities' => [$type->id => 1],
        ])->assertSessionHasErrors();

        $this->assertSame(0, Ticket::withoutGlobalScopes()->count());
    }

    public function test_ticket_qr_verifies_and_staff_can_check_in(): void
    {
        [$event, $type] = $this->makeEvent();

        $ticket = app(TicketSeller::class)->sell($event, [$type->id => 1], 'Ada Obi', 'ada@example.com', null)[0];
        $token = $ticket->verificationToken->token;

        // Anyone scanning sees a valid ticket, but no check-in button.
        $this->get('/v/'.$token)
            ->assertOk()
            ->assertSee('Verified authentic')
            ->assertSee('Ada Obi')
            ->assertDontSee('tickets/'.$ticket->id.'/check-in');

        // A cashier of the company scanning the same QR gets the button and can act.
        $cashier = User::factory()->create(['current_company_id' => $this->company->id]);
        $this->joinCompany($this->company, $cashier, Role::CASHIER);

        $this->actingAs($cashier)->get('/v/'.$token)->assertSee('Check in');

        $this->actingAs($cashier)
            ->from('/v/'.$token)
            ->post('/tickets/'.$ticket->id.'/check-in')
            ->assertRedirect('/v/'.$token);

        $ticket->refresh();
        $this->assertTrue($ticket->isCheckedIn());
        $this->assertSame($cashier->id, $ticket->checked_in_by);

        // The second scan warns instead of silently admitting again.
        $this->actingAs($cashier)
            ->post('/tickets/'.$ticket->id.'/check-in')
            ->assertSessionHasErrors('ticket');

        $this->get('/v/'.$token)->assertSee('Already checked in');
    }

    public function test_a_void_ticket_reads_as_voided(): void
    {
        [$event, $type] = $this->makeEvent();

        $ticket = app(TicketSeller::class)->sell($event, [$type->id => 1], 'Ada Obi', 'ada@example.com', null)[0];
        $ticket->forceFill(['status' => 'void'])->save();

        $this->get('/v/'.$ticket->verificationToken->token)
            ->assertOk()
            ->assertSee('Voided');
    }

    public function test_check_in_is_denied_across_companies(): void
    {
        [$event, $type] = $this->makeEvent();
        $ticket = app(TicketSeller::class)->sell($event, [$type->id => 1], 'Ada Obi', 'ada@example.com', null)[0];

        $outsiderOwner = User::factory()->create();
        $other = Company::create([
            'slug' => 'rival',
            'name' => 'Rival Ltd',
            'owner_id' => $outsiderOwner->id,
            'currency' => 'USD',
        ]);
        $this->joinCompany($other, $outsiderOwner);
        $outsiderOwner->forceFill(['current_company_id' => $other->id])->save();

        // The rival's owner is almighty in their own company and nothing here:
        // the ticket does not even resolve inside their tenant scope.
        $this->actingAs($outsiderOwner)
            ->post('/tickets/'.$ticket->id.'/check-in')
            ->assertNotFound();

        $this->assertFalse($ticket->fresh()->isCheckedIn());
    }

    public function test_event_pages_enforce_permissions(): void
    {
        [$event] = $this->makeEvent();

        // Read Only can look but not edit.
        $viewer = User::factory()->create(['current_company_id' => $this->company->id]);
        $this->joinCompany($this->company, $viewer, Role::READ_ONLY);

        $this->actingAs($viewer)->get('/events')->assertOk();
        $this->actingAs($viewer)->get('/events/'.$event->id)->assertOk();
        $this->actingAs($viewer)->get('/events/'.$event->id.'/edit')->assertForbidden();
        $this->actingAs($viewer)->get('/events/create')->assertForbidden();
    }
}
