<?php

namespace Tests\Feature\Service;

use App\Livewire\Service\Index;
use App\Livewire\Service\Policies;
use App\Livewire\Service\Show;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Service\ServiceScheduling;
use App\Services\Service\TicketDesk;
use Livewire\Livewire;

class ServiceScreensTest extends ServiceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The desk ships switched off, and every `service.*` gate is denied
        // while it is. These screens are the module, so the tests turn it on.
        $this->company->forceFill(['modules' => ['service' => true]])->save();
    }

    public function test_the_board_puts_a_breaching_ticket_at_the_top(): void
    {
        $this->policy();

        $late = app(TicketDesk::class)->open([
            'contact_id' => $this->customer()->id,
            'subject' => 'Generator will not start',
            'priority' => 'urgent',
        ], $this->owner, now()->subMonth());

        Livewire::actingAs($this->owner)
            ->test(Index::class)
            ->assertSee($late->reference)
            ->assertSee('past what we promised');
    }

    public function test_assigning_from_the_queue_goes_through_the_desk(): void
    {
        $this->policy();

        $ticket = app(TicketDesk::class)->open([
            'subject' => 'Printer jams',
        ], $this->owner);

        $technician = $this->memberAt(Role::MANAGER);

        Livewire::actingAs($this->owner)
            ->test(Index::class)
            ->call('startAction', $ticket->id)
            ->set('actingAssignee', (string) $technician->id)
            ->call('assign')
            ->assertHasNoErrors();

        $ticket->refresh();

        $this->assertSame($technician->id, $ticket->assignee_id);
        // The event row is the point: a screen that wrote the column itself
        // would leave the clock's audit trail with a hole in it.
        $this->assertTrue($ticket->events()->where('kind', 'assigned')->exists());
    }

    public function test_a_refused_action_says_why_in_the_desk_s_own_words(): void
    {
        $this->policy();

        $ticket = app(TicketDesk::class)->open(['subject' => 'Door lock broken'], $this->owner);
        app(TicketDesk::class)->close($ticket->refresh(), $this->owner);

        Livewire::actingAs($this->owner)
            ->test(Show::class, ['ticket' => $ticket->refresh()])
            ->call('resolve')
            ->assertHasErrors('resolution')
            ->assertSee('Reopen it first');

        $this->assertSame('closed', $ticket->refresh()->status);
    }

    public function test_completing_a_visit_does_not_let_the_same_person_bill_for_it(): void
    {
        $this->policy();

        $ticket = app(TicketDesk::class)->open(['subject' => 'Aircon leaking'], $this->owner);

        $job = app(ServiceScheduling::class)->schedule($ticket, [
            'scheduled_for' => now(),
        ], $this->owner);

        // A technician who signs work off, and nothing more. The split is the
        // whole reason the two abilities exist separately.
        $technician = $this->memberAt(Role::MANAGER);
        $this->stripBillFromManagers();

        Livewire::actingAs($technician)
            ->test(Show::class, ['ticket' => $ticket])
            ->call('completeJob', $job->id)
            ->assertHasNoErrors();

        $this->assertNotNull($job->refresh()->completed_at);

        Livewire::actingAs($technician)
            ->test(Show::class, ['ticket' => $ticket->refresh()])
            ->call('bill', $job->id)
            ->assertForbidden();

        $this->assertFalse($job->refresh()->isBilled());
    }

    public function test_a_manager_cannot_open_the_promises_screen(): void
    {
        $this->policy();

        $manager = $this->memberAt(Role::MANAGER);

        Livewire::actingAs($manager)->test(Policies::class)->assertForbidden();
    }

    public function test_the_owner_can_change_what_was_promised(): void
    {
        $policy = $this->policy();

        Livewire::actingAs($this->owner)
            ->test(Policies::class)
            ->set('targets.urgent.response', '15')
            // Left empty on purpose: nothing was promised about fixing an
            // urgent fault, and storing zero would breach on arrival.
            ->set('targets.urgent.resolution', '')
            ->call('saveTargets')
            ->assertHasNoErrors();

        $target = $policy->refresh()->targetFor('urgent');

        $this->assertSame(15, $target->response_minutes);
        $this->assertNull($target->resolution_minutes);
    }

    /**
     * Editing a policy pushes the new promise onto tickets still open —
     * and leaves settled tickets alone. A resolved ticket's deadlines are
     * the promise as it stood when the work was done; rewriting them would
     * let a widened policy erase breaches already reported.
     */
    public function test_changing_a_target_recomputes_open_tickets_but_not_settled_ones(): void
    {
        $this->policy();

        $desk = app(TicketDesk::class);

        $open = $desk->open([
            'contact_id' => $this->customer()->id,
            'subject' => 'Line down',
            'priority' => 'urgent',
        ], $this->owner);

        $settled = $desk->open([
            'contact_id' => $this->customer()->id,
            'subject' => 'Already handled',
            'priority' => 'urgent',
        ], $this->owner);
        $desk->resolve($settled, $this->owner, 'Fixed.');

        $openDueBefore = $open->refresh()->resolution_due_at;
        $settledDueBefore = $settled->refresh()->resolution_due_at;

        // Halve the resolution promise: 4 hours becomes 2.
        Livewire::actingAs($this->owner)
            ->test(Policies::class)
            ->set('targets.urgent.resolution', '120')
            ->call('saveTargets')
            ->assertHasNoErrors();

        $this->assertTrue(
            $open->refresh()->resolution_due_at->lt($openDueBefore),
            'The open ticket should be due earlier under the tightened target.'
        );
        $this->assertTrue(
            $settled->refresh()->resolution_due_at->equalTo($settledDueBefore),
            'The settled ticket must keep the deadline it was resolved under.'
        );
    }

    public function test_the_ticket_screen_shows_why_the_clock_is_stopped(): void
    {
        $this->policy();

        $ticket = app(TicketDesk::class)->open(['subject' => 'No dial tone'], $this->owner);

        Livewire::actingAs($this->owner)
            ->test(Show::class, ['ticket' => $ticket])
            ->set('holdNote', 'Asked for the serial number')
            ->call('waitOnCustomer')
            ->assertHasNoErrors()
            ->assertSee('Clock stopped')
            ->assertSee('Asked for the serial number');

        $this->assertSame('pending_customer', $ticket->refresh()->status);
    }

    /** Leave the manager able to finish work but not to price it. */
    protected function stripBillFromManagers(): void
    {
        $role = Role::where('slug', Role::MANAGER)->first();
        $permission = Permission::where('slug', 'service.bill')->first();

        $role->permissions()->detach($permission->id);
    }
}
