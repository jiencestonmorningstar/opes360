<?php

namespace App\Console\Commands;

use App\Models\ServiceTicket;
use App\Support\CurrentCompany;
use Illuminate\Console\Command;

/**
 * Announce SLA breaches nobody has been told about yet.
 *
 * A breach is a fact about the clock, so the board can work it out on demand
 * without anything running — that is the point of storing absolute deadlines.
 * What cannot happen on demand is *telling* somebody. This sweep exists only
 * to raise the event, and it stamps `breach_notified_at` so a desk does not
 * get the same alarm every fifteen minutes for a week.
 *
 * Runs with no tenant in scope, so it names its company on every write rather
 * than leaning on the global scope.
 */
class SweepServiceSlaBreaches extends Command
{
    protected $signature = 'service:sla-sweep';

    protected $description = 'Raise service.sla.breached for tickets that have missed their promise';

    public function handle(CurrentCompany $current): int
    {
        $at = now();

        $breached = ServiceTicket::query()
            ->acrossAllCompanies()
            ->with('company')
            ->onTheClock()
            ->whereNull('breach_notified_at')
            ->where(function ($query) use ($at) {
                $query->where(fn ($q) => $q->whereNull('first_response_at')->where('response_due_at', '<', $at))
                    ->orWhere(fn ($q) => $q->whereNull('resolved_at')->where('resolution_due_at', '<', $at));
            })
            ->get();

        foreach ($breached as $ticket) {
            $ticket->forceFill(['breach_notified_at' => $at])->save();

            $ticket->emitDomainEvent('service.sla.breached', [
                'ticket_id' => $ticket->id,
                'reference' => $ticket->reference,
                'clock' => $ticket->hasBreachedResponse($at) ? 'response' : 'resolution',
                'priority' => $ticket->priority,
                'assignee_id' => $ticket->assignee_id,
            ]);
        }

        $this->info("{$breached->count()} newly breached ticket(s) announced.");

        return self::SUCCESS;
    }
}
