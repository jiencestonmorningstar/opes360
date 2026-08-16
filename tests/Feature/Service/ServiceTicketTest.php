<?php

namespace Tests\Feature\Service;

use App\Models\Role;
use App\Models\ServiceTicket;
use App\Models\ServiceTicketEvent;
use App\Services\Service\TicketDesk;
use App\Support\CurrentCompany;
use Illuminate\Support\Carbon;
use RuntimeException;

class ServiceTicketTest extends ServiceTestCase
{
    protected function desk(): TicketDesk
    {
        return app(TicketDesk::class);
    }

    protected function open(array $attributes = []): ServiceTicket
    {
        $this->policy();

        return $this->desk()->open(array_merge([
            'contact_id' => $this->customer()->id,
            'subject' => 'Printer will not print',
            'description' => 'Jams on every job.',
            'priority' => 'normal',
            'channel' => 'phone',
        ], $attributes), $this->owner);
    }

    public function test_a_ticket_belongs_to_its_company_and_its_customer(): void
    {
        $ticket = $this->open();

        $this->assertSame($this->company->id, $ticket->company_id);
        $this->assertNotNull($ticket->contact);
    }

    /** Statuses are set explicitly — Model::create() does not read DB defaults. */
    public function test_a_new_ticket_is_open_and_unanswered(): void
    {
        $ticket = $this->open();

        $this->assertSame('new', $ticket->status);
        $this->assertNull($ticket->first_response_at);
        $this->assertNotNull($ticket->opened_at);
    }

    /** The reference comes off the existing lease ledger, not max(id)+1. */
    public function test_a_ticket_is_given_a_readable_reference_from_the_number_ledger(): void
    {
        $first = $this->open();
        $second = $this->desk()->open([
            'contact_id' => $this->customer()->id,
            'subject' => 'Second fault',
            'priority' => 'low',
        ], $this->owner);

        $this->assertMatchesRegularExpression('/^TKT-\d{4}-\d{5}$/', $first->reference);
        $this->assertNotSame($first->reference, $second->reference);
    }

    public function test_a_ticket_can_be_assigned_and_the_assignment_is_logged(): void
    {
        $technician = $this->memberAt(Role::SALES_OFFICER);
        $ticket = $this->desk()->assign($this->open(), $technician, $this->owner);

        $this->assertTrue($ticket->assignee->is($technician));
        $this->assertSame(1, ServiceTicketEvent::where('ticket_id', $ticket->id)->where('kind', 'assigned')->count());
    }

    public function test_the_first_response_is_the_one_that_counts(): void
    {
        $ticket = $this->open();

        $this->desk()->recordResponse($ticket, $this->owner, Carbon::parse('2026-09-14 09:30'));
        $ticket = $this->desk()->recordResponse($ticket->refresh(), $this->owner, Carbon::parse('2026-09-14 14:00'));

        $this->assertSame('2026-09-14 09:30', $ticket->first_response_at->format('Y-m-d H:i'));
    }

    public function test_resolving_then_closing_walks_the_status_forward(): void
    {
        $ticket = $this->desk()->resolve($this->open(), $this->owner, 'Replaced the fuser.');

        $this->assertSame('resolved', $ticket->status);
        $this->assertNotNull($ticket->resolved_at);

        $ticket = $this->desk()->close($ticket, $this->owner);

        $this->assertSame('closed', $ticket->status);
        $this->assertNotNull($ticket->closed_at);
    }

    public function test_a_closed_ticket_cannot_be_resolved_again(): void
    {
        $ticket = $this->desk()->close($this->desk()->resolve($this->open(), $this->owner), $this->owner);

        $this->expectException(RuntimeException::class);

        $this->desk()->resolve($ticket, $this->owner);
    }

    /** Every clock-affecting act leaves a row. A deadline nobody can explain is a deadline nobody trusts. */
    public function test_every_status_change_is_recorded(): void
    {
        $ticket = $this->open();

        $this->desk()->waitOnCustomer($ticket, $this->owner, 'Asked for the error code.');
        $this->desk()->resume($ticket->refresh(), $this->owner);
        $this->desk()->resolve($ticket->refresh(), $this->owner);

        $kinds = ServiceTicketEvent::where('ticket_id', $ticket->id)->pluck('kind')->all();

        $this->assertContains('opened', $kinds);
        $this->assertContains('paused', $kinds);
        $this->assertContains('resumed', $kinds);
        $this->assertContains('resolved', $kinds);
    }

    public function test_tickets_of_another_company_are_invisible(): void
    {
        $ticket = $this->open();

        $this->assertSame(1, ServiceTicket::count());

        app(CurrentCompany::class)->set(null);

        $this->assertSame(0, ServiceTicket::count());
        $this->assertNotNull(ServiceTicket::acrossAllCompanies()->find($ticket->id));
    }
}
