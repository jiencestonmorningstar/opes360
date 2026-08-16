<?php

namespace App\Services\Service;

use App\Models\ServiceJob;
use App\Models\ServiceSlaPolicy;
use App\Models\ServiceTicket;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * "What is breaching, and what is about to."
 *
 * A read model, and a query rather than a loop. That is the payoff for storing
 * deadlines as absolute instants: the calendar arithmetic was done once, when
 * something changed, so answering this is `where due_at < now` on an index. A
 * board that instead loaded every open ticket and asked a calendar object
 * about each one would be the first thing to fall over on a desk with four
 * thousand of them — and it is the screen people leave open all day.
 */
class SlaBoard
{
    /** Tickets whose promise has already been missed and is still not met. */
    public function breaching(?Carbon $at = null): Collection
    {
        $at ??= now();

        return $this->onTheClock()
            ->where(function (Builder $query) use ($at) {
                $query->where(fn (Builder $q) => $q
                    ->whereNull('first_response_at')
                    ->where('response_due_at', '<', $at))
                    ->orWhere(fn (Builder $q) => $q
                        ->whereNull('resolved_at')
                        ->where('resolution_due_at', '<', $at));
            })
            ->orderBy('resolution_due_at')
            ->get();
    }

    /**
     * Tickets due within the next $minutes of *working* time.
     *
     * The horizon is walked through each policy's own calendar, which is why
     * this cannot be a single `now() + interval`. At 16:50 on a Friday with an
     * hour of horizon, only ten working minutes exist before the weekend — so
     * everything due on Monday morning is correctly left alone. A wall-clock
     * horizon would sweep the whole Monday queue onto the warning board every
     * Friday evening, and a warning that fires every week is a warning people
     * turn off.
     */
    public function atRisk(int $minutes = 60, ?Carbon $at = null): Collection
    {
        $at ??= now();

        $horizons = ServiceSlaPolicy::query()
            ->get()
            ->mapWithKeys(fn (ServiceSlaPolicy $policy) => [
                $policy->id => Carbon::instance($policy->hours()->add($at, $minutes)),
            ]);

        if ($horizons->isEmpty()) {
            return ServiceTicket::query()->whereRaw('1 = 0')->get();
        }

        return $this->onTheClock()
            ->where(function (Builder $query) use ($horizons, $at) {
                foreach ($horizons as $policyId => $horizon) {
                    $query->orWhere(function (Builder $q) use ($policyId, $horizon, $at) {
                        $q->where('sla_policy_id', $policyId)
                            ->where(function (Builder $clock) use ($horizon, $at) {
                                $clock->where(fn (Builder $c) => $c
                                    ->whereNull('first_response_at')
                                    ->whereBetween('response_due_at', [$at, $horizon]))
                                    ->orWhere(fn (Builder $c) => $c
                                        ->whereNull('resolved_at')
                                        ->whereBetween('resolution_due_at', [$at, $horizon]));
                            });
                    });
                }
            })
            ->orderBy('resolution_due_at')
            ->get();
    }

    /**
     * The counters a desk manager reads first.
     *
     * @return array{open: int, unassigned: int, awaiting_response: int, waiting_on_customer: int, response_breached: int, resolution_breached: int}
     */
    public function summary(?Carbon $at = null): array
    {
        $at ??= now();

        return [
            'open' => $this->onTheClock()->count(),
            'unassigned' => $this->onTheClock()->whereNull('assignee_id')->count(),
            'awaiting_response' => $this->onTheClock()->whereNull('first_response_at')->count(),
            'waiting_on_customer' => ServiceTicket::query()->where('status', 'pending_customer')->count(),
            'response_breached' => $this->onTheClock()
                ->whereNull('first_response_at')
                ->where('response_due_at', '<', $at)
                ->count(),
            'resolution_breached' => $this->onTheClock()
                ->whereNull('resolved_at')
                ->where('resolution_due_at', '<', $at)
                ->count(),
        ];
    }

    /**
     * A technician's day: the visits booked for them on one date.
     *
     * Bounded on start-of-day and end-of-day rather than compared with
     * `whereDate` or a naive `whereBetween` on two dates — a datetime column
     * against a bare date drops everything after midnight on the last day,
     * which here would be the entire working day.
     */
    public function diary(Carbon $from, Carbon $to, ?int $technicianId = null): Collection
    {
        return ServiceJob::query()
            ->whereBetween('scheduled_for', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->whereIn('status', ['scheduled', 'in_progress'])
            ->when($technicianId !== null, fn (Builder $q) => $q->where('technician_id', $technicianId))
            ->orderBy('scheduled_for')
            ->get();
    }

    /**
     * Tickets somebody is expected to be working on right now.
     *
     * Paused and settled tickets are excluded at the query, not filtered out
     * afterwards, so the counters and the lists can never disagree.
     */
    protected function onTheClock(): Builder
    {
        return ServiceTicket::query()->onTheClock();
    }
}
