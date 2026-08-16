<?php

namespace App\Services\Service;

use App\Models\ServiceTicket;
use App\Support\BusinessHours;
use Illuminate\Support\Carbon;

/**
 * The SLA clock.
 *
 * One rule, applied everywhere:
 *
 *     deadline = opened_at + (target + minutes the clock was stopped),
 *     measured on the policy's working calendar.
 *
 * That the deadline is *recomputed from opening* rather than nudged forward
 * each time is the important part. The obvious implementation — "on resume,
 * push both deadlines out by the pause" — is incremental, and incremental
 * arithmetic drifts: escalate a ticket that has been paused twice and you
 * either lose the credited time or double it, and nobody notices until a
 * customer disputes a breach. Recomputing is idempotent. Run it ten times and
 * the answer is the same, whatever order pauses, escalations and reopenings
 * happened in.
 *
 * Stopping the clock covers two situations, and they are the same situation:
 * the ticket is not on the desk's plate. Waiting on the customer is one;
 * sitting resolved before somebody reopens it is the other. A business is not
 * failing to fix something it was told was fixed.
 */
class SlaClock
{
    /**
     * Work out both deadlines from scratch and write them.
     *
     * Safe to call after anything: opening, escalation, a change of policy, a
     * resume, a reopening.
     */
    public function apply(ServiceTicket $ticket): ServiceTicket
    {
        $policy = $ticket->policy;

        if ($policy === null || $ticket->opened_at === null) {
            /*
             * No policy, no deadline — and explicitly null rather than a
             * guessed default. A board that invents a promise nobody made
             * will report breaches nobody agreed to, and the desk will
             * rightly stop believing it.
             */
            $ticket->forceFill(['response_due_at' => null, 'resolution_due_at' => null])->save();

            return $ticket;
        }

        $target = $policy->targetFor($ticket->priority);
        $hours = $policy->hours();
        $stopped = max(0, (int) $ticket->paused_minutes);

        $ticket->forceFill([
            'response_due_at' => $target?->response_minutes === null
                ? null
                : $hours->add($ticket->opened_at, $target->response_minutes + $stopped),
            'resolution_due_at' => $target?->resolution_minutes === null
                ? null
                : $hours->add($ticket->opened_at, $target->resolution_minutes + $stopped),
        ])->save();

        return $ticket;
    }

    /** Stop the clock. The instant is kept so the credit can be measured on resume. */
    public function pause(ServiceTicket $ticket, ?Carbon $at = null): ServiceTicket
    {
        if ($ticket->paused_at !== null) {
            return $ticket;
        }

        $ticket->forceFill(['paused_at' => $at ?? now()])->save();

        return $ticket;
    }

    /**
     * Start it again, crediting back the working time that passed.
     *
     * Working time, not wall time: a customer who takes the weekend to answer
     * has cost the desk nothing, so it gets nothing back. Crediting the whole
     * weekend would hand out 65 hours of slack for a two-day silence and make
     * the resolution target meaningless.
     *
     * @return int the minutes credited
     */
    public function resume(ServiceTicket $ticket, ?Carbon $at = null): int
    {
        if ($ticket->paused_at === null) {
            return 0;
        }

        $credited = $this->hoursFor($ticket)->between($ticket->paused_at, $at ?? now());

        $ticket->forceFill([
            'paused_at' => null,
            'paused_minutes' => (int) $ticket->paused_minutes + $credited,
        ])->save();

        $this->apply($ticket);

        return $credited;
    }

    /**
     * The calendar to count on.
     *
     * A ticket with no policy still gets asked how long a pause lasted — by
     * the reopening path, before anybody has attached a policy — so this
     * answers in wall-clock time rather than refusing.
     */
    public function hoursFor(ServiceTicket $ticket): BusinessHours
    {
        return $ticket->policy?->hours() ?? BusinessHours::continuous();
    }
}
