<?php

namespace App\Services\Service;

use App\Models\ServiceSlaPolicy;
use App\Models\ServiceTicket;
use App\Models\ServiceTicketEvent;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The service desk: raising a ticket and walking it to closed.
 *
 * Every act that could move a deadline goes through here, for one reason —
 * the clock and its audit row have to be written in the same transaction as
 * the status change. A screen that set `status = 'pending_customer'` directly
 * would stop the clock in the eyes of the board while leaving no record of
 * why, and the difference would only surface when a customer argued.
 */
class TicketDesk
{
    public function __construct(
        protected SlaClock $clock,
        protected TicketNumbers $numbers,
    ) {}

    /**
     * Raise a ticket.
     *
     * `status` and `opened_at` are set explicitly rather than left to the
     * column defaults — Model::create() does not read them back, and a ticket
     * with a null status is invisible to every scope on the board.
     */
    public function open(array $attributes, User $by, ?Carbon $at = null): ServiceTicket
    {
        $at ??= now();

        return DB::transaction(function () use ($attributes, $by, $at) {
            $policyId = $attributes['sla_policy_id'] ?? ServiceSlaPolicy::default()?->id;

            $ticket = ServiceTicket::create(array_merge(
                Arr::only($attributes, [
                    'contact_id', 'subject', 'description', 'channel', 'category',
                    'department_id', 'fixed_asset_id', 'project_id', 'assignee_id',
                ]),
                [
                    'reference' => $attributes['reference'] ?? $this->numbers->nextTicket(),
                    'priority' => $attributes['priority'] ?? 'normal',
                    'channel' => $attributes['channel'] ?? 'phone',
                    'status' => 'new',
                    'sla_policy_id' => $policyId,
                    'opened_at' => $at,
                    'paused_minutes' => 0,
                    'created_by' => $by->id,
                ],
            ));

            $this->clock->apply($ticket);

            $this->log($ticket, 'opened', $by, null, 'new', $at, null, $attributes['subject'] ?? null);

            $ticket->emitDomainEvent('service.ticket.opened', [
                'ticket_id' => $ticket->id,
                'reference' => $ticket->reference,
                'priority' => $ticket->priority,
                'contact_id' => $ticket->contact_id,
            ]);

            return $ticket->refresh();
        });
    }

    public function assign(ServiceTicket $ticket, ?User $to, User $by, ?Carbon $at = null): ServiceTicket
    {
        $this->refuseIfSettled($ticket, 'reassigned');

        return DB::transaction(function () use ($ticket, $to, $by, $at) {
            $ticket->forceFill(['assignee_id' => $to?->id])->save();

            $this->log($ticket, 'assigned', $by, $ticket->status, $ticket->status, $at, null,
                $to === null ? 'Unassigned' : "Assigned to {$to->name}");

            $ticket->emitDomainEvent('service.ticket.assigned', [
                'ticket_id' => $ticket->id,
                'assignee_id' => $to?->id,
            ]);

            return $ticket->refresh();
        });
    }

    /**
     * Somebody answered the customer.
     *
     * The *first* answer is the one the promise was about, so a second call
     * changes nothing. Recording the latest reply here instead would let a
     * desk that answered late look punctual by replying again.
     */
    public function recordResponse(ServiceTicket $ticket, User $by, ?Carbon $at = null): ServiceTicket
    {
        if ($ticket->first_response_at !== null) {
            return $ticket;
        }

        $at ??= now();

        return DB::transaction(function () use ($ticket, $by, $at) {
            $from = $ticket->status;

            $ticket->forceFill([
                'first_response_at' => $at,
                'status' => $ticket->status === 'new' ? 'open' : $ticket->status,
            ])->save();

            $this->log($ticket, 'responded', $by, $from, $ticket->status, $at);

            if ($ticket->hasBreachedResponse($at)) {
                $ticket->emitDomainEvent('service.sla.breached', [
                    'ticket_id' => $ticket->id,
                    'clock' => 'response',
                    'due_at' => $ticket->response_due_at?->toIso8601String(),
                ]);
            }

            return $ticket->refresh();
        });
    }

    /** The ball is in the customer's court, so the clock stops. */
    public function waitOnCustomer(ServiceTicket $ticket, User $by, ?string $note = null, ?Carbon $at = null): ServiceTicket
    {
        $this->refuseIfSettled($ticket, 'put on hold');

        $at ??= now();

        return DB::transaction(function () use ($ticket, $by, $note, $at) {
            $from = $ticket->status;

            $ticket->forceFill(['status' => 'pending_customer'])->save();
            $this->clock->pause($ticket, $at);

            $this->log($ticket, 'paused', $by, $from, 'pending_customer', $at, null, $note);

            return $ticket->refresh();
        });
    }

    /** The customer came back. The working time they took is credited to the deadline. */
    public function resume(ServiceTicket $ticket, User $by, ?Carbon $at = null): ServiceTicket
    {
        $at ??= now();

        return DB::transaction(function () use ($ticket, $by, $at) {
            $from = $ticket->status;
            $credited = $this->clock->resume($ticket, $at);

            $ticket->forceFill(['status' => 'open'])->save();
            $this->clock->apply($ticket);

            $this->log($ticket, 'resumed', $by, $from, 'open', $at, $credited);

            return $ticket->refresh();
        });
    }

    /**
     * Escalate, or step down.
     *
     * The new target is measured from when the ticket was raised, not from
     * now. Measuring from now would mean escalating a three-hour-old ticket
     * handed the desk a fresh hour to answer it — turning an escalation into
     * a way of clearing a breach.
     */
    public function changePriority(ServiceTicket $ticket, string $priority, User $by, ?Carbon $at = null): ServiceTicket
    {
        if (! array_key_exists($priority, ServiceTicket::PRIORITIES)) {
            throw new RuntimeException("\"{$priority}\" is not a priority this desk uses.");
        }

        $this->refuseIfSettled($ticket, 'reprioritised');

        return DB::transaction(function () use ($ticket, $priority, $by, $at) {
            $was = $ticket->priority;

            $ticket->forceFill(['priority' => $priority])->save();
            $this->clock->apply($ticket);

            $this->log($ticket, 'escalated', $by, $ticket->status, $ticket->status, $at, null,
                "Priority {$was} → {$priority}");

            $ticket->emitDomainEvent('service.ticket.reprioritised', [
                'ticket_id' => $ticket->id,
                'from' => $was,
                'to' => $priority,
            ]);

            return $ticket->refresh();
        });
    }

    public function resolve(ServiceTicket $ticket, User $by, ?string $resolution = null, ?Carbon $at = null): ServiceTicket
    {
        $this->refuseIfSettled($ticket, 'resolved');

        $at ??= now();

        return DB::transaction(function () use ($ticket, $by, $resolution, $at) {
            $from = $ticket->status;

            $ticket->forceFill([
                'status' => 'resolved',
                'resolved_at' => $at,
                'resolution' => $resolution ?? $ticket->resolution,
                // The clock stops here too, not only when the customer is
                // holding things up. A ticket sitting resolved is off the
                // desk's plate; if it is reopened a week later, that week was
                // not the desk failing to fix it.
                'paused_at' => $ticket->paused_at ?? $at,
            ])->save();

            $this->log($ticket, 'resolved', $by, $from, 'resolved', $at, null, $resolution);

            if ($ticket->hasBreachedResolution($at)) {
                $ticket->emitDomainEvent('service.sla.breached', [
                    'ticket_id' => $ticket->id,
                    'clock' => 'resolution',
                    'due_at' => $ticket->resolution_due_at?->toIso8601String(),
                ]);
            }

            $ticket->emitDomainEvent('service.ticket.resolved', [
                'ticket_id' => $ticket->id,
                'reference' => $ticket->reference,
            ]);

            return $ticket->refresh();
        });
    }

    public function close(ServiceTicket $ticket, User $by, ?Carbon $at = null): ServiceTicket
    {
        if ($ticket->status === 'closed') {
            return $ticket;
        }

        $at ??= now();

        return DB::transaction(function () use ($ticket, $by, $at) {
            $from = $ticket->status;

            $ticket->forceFill([
                'status' => 'closed',
                'closed_at' => $at,
                // Closing without an explicit resolution still counts as
                // resolved then: otherwise the resolution clock would show as
                // never met on a ticket the customer considers finished.
                'resolved_at' => $ticket->resolved_at ?? $at,
                'paused_at' => $ticket->paused_at ?? $at,
            ])->save();

            $this->log($ticket, 'closed', $by, $from, 'closed', $at);

            $ticket->emitDomainEvent('service.ticket.closed', ['ticket_id' => $ticket->id]);

            return $ticket->refresh();
        });
    }

    /**
     * It came back.
     *
     * The original opening date is kept — this is the same fault, and
     * restarting the clock would erase how long the customer has really been
     * living with it. What the desk gets back is the time the ticket spent
     * resolved, credited as working minutes exactly like a customer pause.
     */
    public function reopen(ServiceTicket $ticket, User $by, ?string $note = null, ?Carbon $at = null): ServiceTicket
    {
        if ($ticket->isOpen()) {
            return $ticket;
        }

        $at ??= now();

        return DB::transaction(function () use ($ticket, $by, $note, $at) {
            $from = $ticket->status;
            $credited = $this->clock->resume($ticket, $at);

            $ticket->forceFill([
                'status' => 'open',
                'resolved_at' => null,
                'closed_at' => null,
                'breach_notified_at' => null,
            ])->save();

            $this->clock->apply($ticket);

            $this->log($ticket, 'reopened', $by, $from, 'open', $at, $credited, $note);

            $ticket->emitDomainEvent('service.ticket.reopened', ['ticket_id' => $ticket->id]);

            return $ticket->refresh();
        });
    }

    public function note(ServiceTicket $ticket, User $by, string $note, ?Carbon $at = null): ServiceTicketEvent
    {
        return $this->log($ticket, 'note', $by, $ticket->status, $ticket->status, $at, null, $note);
    }

    protected function refuseIfSettled(ServiceTicket $ticket, string $verb): void
    {
        if ($ticket->isSettled()) {
            throw new RuntimeException(
                "{$ticket->reference} is {$ticket->status} and cannot be {$verb}. Reopen it first."
            );
        }
    }

    protected function log(
        ServiceTicket $ticket,
        string $kind,
        User $by,
        ?string $from,
        ?string $to,
        ?Carbon $at = null,
        ?int $clockMinutes = null,
        ?string $note = null,
    ): ServiceTicketEvent {
        return ServiceTicketEvent::create([
            'company_id' => $ticket->company_id,
            'ticket_id' => $ticket->id,
            'kind' => $kind,
            'from_status' => $from,
            'to_status' => $to,
            'clock_minutes' => $clockMinutes,
            'note' => $note,
            'user_id' => $by->id,
            'occurred_at' => $at ?? now(),
        ]);
    }
}
