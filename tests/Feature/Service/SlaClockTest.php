<?php

namespace Tests\Feature\Service;

use App\Models\ServiceTicket;
use App\Services\Service\TicketDesk;
use Illuminate\Support\Carbon;

/**
 * The clock, end to end on a real ticket.
 *
 * Time is frozen in every test here. An SLA deadline that depends on how long
 * the test suite took to run is a test that fails on a slow machine at 4am.
 */
class SlaClockTest extends ServiceTestCase
{
    protected function desk(): TicketDesk
    {
        return app(TicketDesk::class);
    }

    protected function openAt(string $at, string $priority = 'high'): ServiceTicket
    {
        $this->policy();

        Carbon::setTestNow(Carbon::parse($at, 'UTC'));

        return $this->desk()->open([
            'contact_id' => $this->customer()->id,
            'subject' => 'Line down',
            'priority' => $priority,
        ], $this->owner);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_deadlines_are_set_from_the_policy_target_for_the_priority(): void
    {
        // High: 60 minutes to respond, 8 working hours to resolve.
        $ticket = $this->openAt('2026-09-14 09:00');

        $this->assertSame('2026-09-14 10:00', $ticket->response_due_at->format('Y-m-d H:i'));
        $this->assertSame('2026-09-14 17:00', $ticket->resolution_due_at->format('Y-m-d H:i'));
    }

    /** The Friday-afternoon case, on the ticket rather than on the calendar object. */
    public function test_a_friday_evening_ticket_is_due_on_monday(): void
    {
        $ticket = $this->openAt('2026-09-11 18:30');

        $this->assertSame('2026-09-14 09:00', $ticket->response_due_at->format('Y-m-d H:i'));
    }

    public function test_a_ticket_with_no_policy_carries_no_deadline_rather_than_a_guessed_one(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-14 09:00', 'UTC'));

        $ticket = $this->desk()->open([
            'contact_id' => $this->customer()->id,
            'subject' => 'No policy configured',
            'priority' => 'normal',
        ], $this->owner);

        $this->assertNull($ticket->sla_policy_id);
        $this->assertNull($ticket->response_due_at);
        $this->assertNull($ticket->resolution_due_at);
        $this->assertFalse($ticket->hasBreached());
    }

    public function test_waiting_on_the_customer_pushes_the_deadline_by_the_working_time_lost(): void
    {
        $ticket = $this->openAt('2026-09-14 09:00');

        $this->assertSame('2026-09-14 17:00', $ticket->resolution_due_at->format('Y-m-d H:i'));

        Carbon::setTestNow(Carbon::parse('2026-09-14 10:00', 'UTC'));
        $this->desk()->waitOnCustomer($ticket, $this->owner, 'Waiting for the site key.');

        // Two working hours later the customer answers.
        Carbon::setTestNow(Carbon::parse('2026-09-14 12:00', 'UTC'));
        $ticket = $this->desk()->resume($ticket->refresh(), $this->owner);

        // The two hours are given back as working time: Monday is full, so the
        // deadline lands two hours into Tuesday rather than at 19:00 tonight.
        $this->assertSame(120, $ticket->paused_minutes);
        $this->assertSame('2026-09-15 10:00', $ticket->resolution_due_at->format('Y-m-d H:i'));
    }

    /** A weekend spent waiting on a customer costs the business nothing, because it cost nobody anything. */
    public function test_a_pause_over_a_weekend_only_credits_working_time(): void
    {
        $ticket = $this->openAt('2026-09-11 09:00');

        Carbon::setTestNow(Carbon::parse('2026-09-11 16:00', 'UTC'));
        $this->desk()->waitOnCustomer($ticket, $this->owner);

        Carbon::setTestNow(Carbon::parse('2026-09-14 09:00', 'UTC'));
        $ticket = $this->desk()->resume($ticket->refresh(), $this->owner);

        // Friday 16:00→17:00 and Monday 08:00→09:00: two hours, not 65.
        $this->assertSame(120, $ticket->paused_minutes);
        $this->assertSame('2026-09-14 10:00', $ticket->resolution_due_at->format('Y-m-d H:i'));
    }

    /** A paused ticket cannot breach: nobody is sitting on it. */
    public function test_a_paused_ticket_does_not_breach(): void
    {
        $ticket = $this->openAt('2026-09-14 09:00');

        Carbon::setTestNow(Carbon::parse('2026-09-14 09:30', 'UTC'));
        $ticket = $this->desk()->waitOnCustomer($ticket, $this->owner);

        Carbon::setTestNow(Carbon::parse('2026-09-18 09:00', 'UTC'));

        $this->assertTrue($ticket->isPaused());
        $this->assertFalse($ticket->hasBreachedResponse());
        $this->assertFalse($ticket->hasBreachedResolution());
    }

    public function test_a_missed_response_is_a_breach(): void
    {
        $ticket = $this->openAt('2026-09-14 09:00');

        Carbon::setTestNow(Carbon::parse('2026-09-14 10:30', 'UTC'));

        $this->assertTrue($ticket->fresh()->hasBreachedResponse());
    }

    /** Answering late is still a breach afterwards — it does not un-happen. */
    public function test_a_late_response_stays_a_breach_once_answered(): void
    {
        $ticket = $this->openAt('2026-09-14 09:00');

        Carbon::setTestNow(Carbon::parse('2026-09-14 11:00', 'UTC'));
        $ticket = $this->desk()->recordResponse($ticket, $this->owner);

        Carbon::setTestNow(Carbon::parse('2026-09-14 11:30', 'UTC'));

        $this->assertTrue($ticket->fresh()->hasBreachedResponse());
    }

    public function test_answering_in_time_stops_the_response_clock(): void
    {
        $ticket = $this->openAt('2026-09-14 09:00');

        Carbon::setTestNow(Carbon::parse('2026-09-14 09:20', 'UTC'));
        $this->desk()->recordResponse($ticket, $this->owner);

        Carbon::setTestNow(Carbon::parse('2026-09-16 09:00', 'UTC'));

        $this->assertFalse($ticket->fresh()->hasBreachedResponse());
    }

    /**
     * Raising the priority tightens the deadline from when the ticket was
     * raised, not from now — otherwise escalating a ticket that is already
     * three hours old would hand the desk a fresh hour to answer it.
     */
    public function test_escalating_recomputes_the_deadline_from_when_it_was_opened(): void
    {
        $ticket = $this->openAt('2026-09-14 09:00', 'normal');

        Carbon::setTestNow(Carbon::parse('2026-09-14 10:00', 'UTC'));
        $ticket = $this->desk()->changePriority($ticket, 'urgent', $this->owner);

        // Urgent is 30 minutes from opening: already due, half an hour ago.
        $this->assertSame('2026-09-14 09:30', $ticket->response_due_at->format('Y-m-d H:i'));
        $this->assertTrue($ticket->hasBreachedResponse());
    }

    /** Recomputing must keep credited pause time, or escalating quietly cancels it. */
    public function test_escalating_keeps_time_already_credited_back(): void
    {
        $ticket = $this->openAt('2026-09-14 09:00', 'normal');

        Carbon::setTestNow(Carbon::parse('2026-09-14 09:30', 'UTC'));
        $this->desk()->waitOnCustomer($ticket, $this->owner);

        Carbon::setTestNow(Carbon::parse('2026-09-14 10:30', 'UTC'));
        $ticket = $this->desk()->resume($ticket->refresh(), $this->owner);

        $ticket = $this->desk()->changePriority($ticket, 'high', $this->owner);

        // High is 60 minutes, plus the hour the customer kept us waiting.
        $this->assertSame(60, $ticket->paused_minutes);
        $this->assertSame('2026-09-14 11:00', $ticket->response_due_at->format('Y-m-d H:i'));
    }

    /**
     * A ticket reopened a week after it was resolved must not arrive already
     * breached. The desk had it off its plate legitimately.
     */
    public function test_reopening_credits_back_the_time_it_spent_resolved(): void
    {
        $ticket = $this->openAt('2026-09-14 09:00');
        $original = $ticket->resolution_due_at->copy();

        Carbon::setTestNow(Carbon::parse('2026-09-14 10:00', 'UTC'));
        $this->desk()->resolve($ticket, $this->owner);

        Carbon::setTestNow(Carbon::parse('2026-09-16 10:00', 'UTC'));
        $ticket = $this->desk()->reopen($ticket->refresh(), $this->owner, 'Fault came back.');

        $this->assertSame('open', $ticket->status);
        $this->assertNull($ticket->resolved_at);
        $this->assertTrue($ticket->resolution_due_at->gt($original));
        $this->assertFalse($ticket->hasBreachedResolution());
    }

    public function test_a_continuous_policy_counts_wall_clock_time(): void
    {
        $this->policy(['clock' => 'calendar', 'business_hours' => []]);

        Carbon::setTestNow(Carbon::parse('2026-09-11 18:30', 'UTC'));

        $ticket = $this->desk()->open([
            'contact_id' => $this->customer()->id,
            'subject' => 'Datacentre alarm',
            'priority' => 'high',
        ], $this->owner);

        $this->assertSame('2026-09-11 19:30', $ticket->response_due_at->format('Y-m-d H:i'));
    }
}
