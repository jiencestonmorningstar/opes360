<?php

namespace App\Livewire\Service;

use App\Models\ServiceJob;
use App\Models\ServiceTicket;
use App\Models\User;
use App\Services\Service\ServiceBilling;
use App\Services\Service\ServiceScheduling;
use App\Services\Service\TicketDesk;
use App\Support\CurrentCompany;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use RuntimeException;

/**
 * One ticket: the fault, what has been done about it, and where the promise
 * stands.
 *
 * The event feed is the point of the page as much as the fault is. A deadline
 * that has moved by ninety minutes is either defensible or it is an argument
 * with the customer, and it is only defensible if the screen can show who
 * paused the clock, when, and how much working time that turned out to be.
 */
class Show extends Component
{
    public ServiceTicket $ticket;

    // ── Note / resolution ───────────────────────────────────────────────
    public string $note = '';

    public string $resolution = '';

    public string $holdNote = '';

    // ── Assign & priority ───────────────────────────────────────────────
    public ?string $assigneeId = null;

    public string $priority = 'normal';

    // ── Book a visit ────────────────────────────────────────────────────
    public bool $booking = false;

    public ?string $technicianId = null;

    public string $scheduledFor = '';

    public string $estimatedMinutes = '';

    public bool $jobIsBillable = true;

    public string $visitNotes = '';

    // ── Completing a visit, and what it consumed ────────────────────────
    public ?string $completing = null;

    public string $completionNotes = '';

    public ?string $timeJob = null;

    public string $hours = '';

    public string $timeNotes = '';

    public ?string $partJob = null;

    public string $partDescription = '';

    public string $partQuantity = '1';

    public string $partPrice = '0';

    public function mount(ServiceTicket $ticket): void
    {
        Gate::authorize('service.view');

        $this->ticket = $ticket;
        $this->assigneeId = $ticket->assignee_id === null ? null : (string) $ticket->assignee_id;
        $this->priority = $ticket->priority;
    }

    public function assign(): void
    {
        Gate::authorize('service.assign');

        $to = $this->assigneeId === null || $this->assigneeId === ''
            ? null
            : User::find((int) $this->assigneeId);

        $this->through(fn (TicketDesk $desk) => $desk->assign($this->ticket, $to, auth()->user()), 'assigneeId');
    }

    public function reprioritise(): void
    {
        Gate::authorize('service.update');

        $this->through(
            fn (TicketDesk $desk) => $desk->changePriority($this->ticket, $this->priority, auth()->user()),
            'priority'
        );
    }

    /** Somebody answered the customer. Only the first answer moves the clock. */
    public function recordResponse(): void
    {
        Gate::authorize('service.update');

        $this->through(fn (TicketDesk $desk) => $desk->recordResponse($this->ticket, auth()->user()), 'note');
    }

    public function addNote(): void
    {
        Gate::authorize('service.update');

        $this->validate(['note' => ['required', 'string', 'max:2000']]);

        app(TicketDesk::class)->note($this->ticket, auth()->user(), trim($this->note));

        $this->note = '';
        $this->ticket = $this->ticket->fresh();
    }

    public function waitOnCustomer(): void
    {
        Gate::authorize('service.update');

        $this->through(
            fn (TicketDesk $desk) => $desk->waitOnCustomer($this->ticket, auth()->user(), $this->holdNote ?: null),
            'holdNote'
        );

        $this->holdNote = '';
    }

    public function resume(): void
    {
        Gate::authorize('service.update');

        $this->through(fn (TicketDesk $desk) => $desk->resume($this->ticket, auth()->user()), 'holdNote');
    }

    public function resolve(): void
    {
        Gate::authorize('service.update');

        $this->through(
            fn (TicketDesk $desk) => $desk->resolve($this->ticket, auth()->user(), $this->resolution ?: null),
            'resolution'
        );
    }

    public function close(): void
    {
        Gate::authorize('service.update');

        $this->through(fn (TicketDesk $desk) => $desk->close($this->ticket, auth()->user()), 'resolution');
    }

    public function reopen(): void
    {
        Gate::authorize('service.update');

        $this->through(
            fn (TicketDesk $desk) => $desk->reopen($this->ticket, auth()->user(), $this->note ?: null),
            'resolution'
        );
    }

    public function bookVisit(): void
    {
        Gate::authorize('service.schedule');

        $this->validate([
            'scheduledFor' => ['required', 'date'],
            'estimatedMinutes' => ['nullable', 'numeric', 'min:1', 'max:10000'],
            'visitNotes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            app(ServiceScheduling::class)->schedule($this->ticket, [
                'technician_id' => $this->technicianId === null || $this->technicianId === ''
                    ? null
                    : (int) $this->technicianId,
                'scheduled_for' => Carbon::parse($this->scheduledFor),
                'estimated_minutes' => $this->estimatedMinutes === '' ? null : (int) $this->estimatedMinutes,
                'is_billable' => $this->jobIsBillable,
                'on_site_notes' => $this->visitNotes ?: null,
            ], auth()->user());
        } catch (RuntimeException $e) {
            $this->addError('scheduledFor', $e->getMessage());

            return;
        }

        $this->reset('booking', 'scheduledFor', 'estimatedMinutes', 'visitNotes');
        $this->jobIsBillable = true;
        $this->ticket = $this->ticket->fresh();
        $this->dispatch('toast', message: 'Visit booked.');
    }

    public function startJob(string $id): void
    {
        Gate::authorize('service.schedule');

        try {
            app(ServiceScheduling::class)->start($this->job($id));
        } catch (RuntimeException $e) {
            $this->addError('jobs', $e->getMessage());

            return;
        }

        $this->ticket = $this->ticket->fresh();
    }

    public function completeJob(string $id): void
    {
        Gate::authorize('service.complete');

        try {
            app(ServiceScheduling::class)->complete($this->job($id), auth()->user(), $this->completionNotes ?: null);
        } catch (RuntimeException $e) {
            $this->addError('jobs', $e->getMessage());

            return;
        }

        $this->reset('completing', 'completionNotes');
        $this->ticket = $this->ticket->fresh();
        $this->dispatch('toast', message: 'Visit completed.');
    }

    public function cancelJob(string $id): void
    {
        Gate::authorize('service.schedule');

        try {
            app(ServiceScheduling::class)->cancel($this->job($id));
        } catch (RuntimeException $e) {
            $this->addError('jobs', $e->getMessage());

            return;
        }

        $this->ticket = $this->ticket->fresh();
    }

    public function logTime(string $id): void
    {
        Gate::authorize('service.complete');

        $this->validate([
            'hours' => ['required', 'numeric', 'min:0.01', 'max:24'],
            'timeNotes' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            app(ServiceScheduling::class)->logTime(
                $this->job($id),
                auth()->user(),
                (float) $this->hours,
                ['notes' => $this->timeNotes ?: null],
            );
        } catch (RuntimeException $e) {
            $this->addError('hours', $e->getMessage());

            return;
        }

        $this->reset('timeJob', 'hours', 'timeNotes');
        $this->ticket = $this->ticket->fresh();
    }

    public function addPart(string $id): void
    {
        Gate::authorize('service.complete');

        $this->validate([
            'partDescription' => ['required', 'string', 'max:200'],
            'partQuantity' => ['required', 'numeric', 'min:0.001'],
            'partPrice' => ['required', 'numeric', 'min:0'],
        ]);

        try {
            app(ServiceScheduling::class)->addPart($this->job($id), [
                'description' => $this->partDescription,
                'quantity' => (float) $this->partQuantity,
                'unit_price' => (float) $this->partPrice,
            ]);
        } catch (RuntimeException $e) {
            $this->addError('partDescription', $e->getMessage());

            return;
        }

        $this->reset('partJob', 'partDescription');
        $this->partQuantity = '1';
        $this->partPrice = '0';
        $this->ticket = $this->ticket->fresh();
    }

    /**
     * Draft the invoice for a visit.
     *
     * Its own permission, and deliberately not implied by completing the work:
     * a technician says the machine runs again; whether that visit is
     * chargeable under the customer's contract is somebody else's call.
     *
     * Drafting only. The invoice is an ordinary sales document and it is
     * looked at by a person before it goes anywhere near the customer.
     */
    public function bill(string $id): void
    {
        Gate::authorize('service.bill');

        try {
            app(ServiceBilling::class)->draft($this->job($id), auth()->user());
        } catch (RuntimeException $e) {
            $this->addError('jobs', $e->getMessage());

            return;
        }

        $this->ticket = $this->ticket->fresh();
        $this->dispatch('toast', message: 'Invoice drafted. It still has to be checked and issued.');
    }

    public function render(): View
    {
        Gate::authorize('service.view');

        $company = app(CurrentCompany::class)->get();

        return view('livewire.service.show', [
            'events' => $this->ticket->events()->with('user')->get(),
            'jobs' => $this->ticket->jobs()->with(['technician', 'parts', 'timeEntries.user', 'invoice'])->get(),
            'people' => $company?->users()->orderBy('name')->get(['users.id', 'users.name']) ?? collect(),
            'priorities' => ServiceTicket::PRIORITIES,
        ])->layout('components.layouts.app', [
            'title' => $this->ticket->reference ?? 'Ticket',
            'active' => 'service',
        ]);
    }

    /** A visit belonging to this ticket, and no other. */
    protected function job(string $id): ServiceJob
    {
        return ServiceJob::where('ticket_id', $this->ticket->id)->findOrFail($id);
    }

    /**
     * Run a desk call and put its refusal on the screen.
     *
     * The services refuse in plain business language — "TKT-1 is closed and
     * cannot be resolved. Reopen it first." — so the message is shown as
     * written rather than translated into a generic failure.
     */
    protected function through(callable $call, string $field): void
    {
        try {
            $call(app(TicketDesk::class));
        } catch (RuntimeException $e) {
            $this->addError($field, $e->getMessage());

            return;
        }

        $this->ticket = $this->ticket->fresh();
        $this->priority = $this->ticket->priority;
        $this->assigneeId = $this->ticket->assignee_id === null ? null : (string) $this->ticket->assignee_id;
    }
}
