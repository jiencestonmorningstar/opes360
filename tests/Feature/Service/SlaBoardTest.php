<?php

namespace Tests\Feature\Service;

use App\Models\ServiceTicket;
use App\Services\Service\SlaBoard;
use App\Services\Service\TicketDesk;
use Illuminate\Support\Carbon;

/**
 * "What is breaching, and what is about to."
 *
 * Every assertion here is really about one thing: the answer comes out of the
 * database as a query. A desk with four thousand open tickets cannot afford a
 * board that loads them all and asks each one.
 */
class SlaBoardTest extends ServiceTestCase
{
    protected function board(): SlaBoard
    {
        return app(SlaBoard::class);
    }

    protected function desk(): TicketDesk
    {
        return app(TicketDesk::class);
    }

    protected function openAt(string $at, string $priority, string $subject): ServiceTicket
    {
        Carbon::setTestNow(Carbon::parse($at, 'UTC'));

        return $this->desk()->open([
            'contact_id' => $this->customer()->id,
            'subject' => $subject,
            'priority' => $priority,
        ], $this->owner);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_it_lists_what_has_already_breached(): void
    {
        $this->policy();

        $late = $this->openAt('2026-09-14 08:30', 'urgent', 'Late');   // 30 min to respond
        $this->openAt('2026-09-14 09:00', 'low', 'Comfortable');       // 4 hours

        Carbon::setTestNow(Carbon::parse('2026-09-14 09:30', 'UTC'));

        $breaching = $this->board()->breaching()->pluck('id');

        $this->assertTrue($breaching->contains($late->id));
        $this->assertCount(1, $breaching);
    }

    public function test_it_warns_before_the_deadline_rather_than_after(): void
    {
        $this->policy();

        $soon = $this->openAt('2026-09-14 09:00', 'urgent', 'Due in 30');

        Carbon::setTestNow(Carbon::parse('2026-09-14 09:15', 'UTC'));

        $this->assertTrue($this->board()->atRisk(30)->pluck('id')->contains($soon->id));
        $this->assertFalse($this->board()->breaching()->pluck('id')->contains($soon->id));
    }

    /**
     * The horizon is measured in working time too. At 16:45 on a Friday,
     * "due within the next two hours" must not sweep in everything due on
     * Monday morning — nobody is going to breach those overnight.
     */
    public function test_the_at_risk_horizon_is_measured_in_working_time(): void
    {
        $this->policy();

        $monday = $this->openAt('2026-09-11 16:45', 'high', 'Answer on Monday'); // 60 min → Mon 08:45

        Carbon::setTestNow(Carbon::parse('2026-09-11 16:50', 'UTC'));

        // Ten wall-clock minutes to closing, so only ten working minutes exist
        // between now and the horizon — the Monday deadline is well past it.
        $this->assertFalse($this->board()->atRisk(15)->pluck('id')->contains($monday->id));

        // Give it three working hours of horizon and it does show up.
        $this->assertTrue($this->board()->atRisk(180)->pluck('id')->contains($monday->id));
    }

    public function test_a_ticket_waiting_on_the_customer_is_off_the_board(): void
    {
        $this->policy();

        $ticket = $this->openAt('2026-09-14 08:30', 'urgent', 'Waiting');

        Carbon::setTestNow(Carbon::parse('2026-09-14 08:45', 'UTC'));
        $this->desk()->waitOnCustomer($ticket, $this->owner);

        Carbon::setTestNow(Carbon::parse('2026-09-14 11:00', 'UTC'));

        $this->assertCount(0, $this->board()->breaching());
        $this->assertCount(0, $this->board()->atRisk(60));
    }

    public function test_a_closed_ticket_is_off_the_board(): void
    {
        $this->policy();

        $ticket = $this->openAt('2026-09-14 08:30', 'urgent', 'Sorted');

        Carbon::setTestNow(Carbon::parse('2026-09-14 11:00', 'UTC'));
        $this->desk()->close($this->desk()->resolve($ticket, $this->owner), $this->owner);

        $this->assertCount(0, $this->board()->breaching());
    }

    public function test_the_summary_counts_the_two_clocks_separately(): void
    {
        $this->policy();

        $this->openAt('2026-09-14 08:30', 'urgent', 'Nobody answered');

        Carbon::setTestNow(Carbon::parse('2026-09-14 13:30', 'UTC'));

        $summary = $this->board()->summary();

        $this->assertSame(1, $summary['response_breached']);
        $this->assertSame(1, $summary['resolution_breached']);
        $this->assertSame(1, $summary['open']);
    }
}
