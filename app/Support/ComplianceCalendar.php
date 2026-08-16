<?php

namespace App\Support;

use App\Models\ComplianceFiling;
use App\Models\ComplianceObligation;
use App\Models\Risk;
use App\Models\RiskControl;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * What is due, and what has already been missed.
 *
 * The heart of the compliance module. Obligations, filings and risks are all
 * bookkeeping in service of this: one honest answer to the question somebody
 * asks on a Monday morning, produced in one place so that a dashboard, a
 * reminder and a report cannot each count it slightly differently.
 *
 * A read model rather than a service — it changes nothing, and everything it
 * returns can be recomputed from the tables at any moment.
 */
class ComplianceCalendar
{
    /**
     * Deadlines inside a window, both ends included.
     *
     * The inclusive end is not a nicety. `next_due_on` is a date cast, so a
     * bound carrying a time compares against midnight and drops everything
     * falling on the final day of the range — which on this particular
     * calendar is the deadline itself. Bounding on startOfDay/endOfDay is the
     * fix, and there is a test pinning it.
     *
     * @return Collection<int, ComplianceObligation>
     */
    public function dueBetween(Carbon $from, Carbon $to): Collection
    {
        return ComplianceObligation::query()
            ->active()
            ->dueBetween($from, $to)
            ->orderBy('next_due_on')
            ->get();
    }

    /**
     * Missed. Kept apart from "due soon" throughout, because a list that
     * mixes the deadlines you can still meet with the ones you cannot is a
     * list people stop opening — and an unopened register is the exact
     * failure this module exists to prevent.
     *
     * @return Collection<int, ComplianceObligation>
     */
    public function overdue(): Collection
    {
        return ComplianceObligation::query()
            ->overdue()
            ->orderBy('next_due_on')
            ->get();
    }

    /**
     * Close enough to act on, judged per obligation rather than by one global
     * number of days: a return prepared in an afternoon and a renewal needing
     * a bank attestation do not want the same notice.
     *
     * Filtered in PHP after a cheap SQL narrowing, because the cut-off is a
     * different column on every row.
     *
     * @return Collection<int, ComplianceObligation>
     */
    public function dueSoon(): Collection
    {
        $today = Carbon::today();

        return ComplianceObligation::query()
            ->active()
            ->whereNotNull('next_due_on')
            ->where('next_due_on', '>=', $today->copy()->startOfDay())
            ->orderBy('next_due_on')
            ->get()
            ->filter(fn (ComplianceObligation $o) => $o->isDueSoon())
            ->values();
    }

    /**
     * Everything still ahead. Excludes what has already been missed — that is
     * a different question with a different answer, and overdue() answers it.
     *
     * @return Collection<int, ComplianceObligation>
     */
    public function upcoming(int $days = 90): Collection
    {
        return $this->dueBetween(Carbon::today(), Carbon::today()->addDays($days));
    }

    /**
     * Obligations somebody has started but not finished. Not a substitute for
     * the overdue list — a draft is not a filing — but the thing to show
     * beside it, so a register does not chase people already doing the work.
     *
     * @return Collection<int, ComplianceFiling>
     */
    public function inProgress(): Collection
    {
        return ComplianceFiling::query()
            ->open()
            ->with('obligation')
            ->orderBy('due_on')
            ->get();
    }

    /**
     * Filings that missed their deadline. The evidence trail an inspector
     * asks for, and the number a business should be watching fall.
     *
     * @return Collection<int, ComplianceFiling>
     */
    public function lateFilings(Carbon $since): Collection
    {
        return ComplianceFiling::query()
            ->where('status', 'completed')
            ->where('completed_on', '>=', $since->copy()->startOfDay())
            ->whereColumn('completed_on', '>', 'due_on')
            ->with('obligation')
            ->orderByDesc('completed_on')
            ->get();
    }

    /**
     * Risks nobody has looked at since they were promised they would.
     *
     * @return Collection<int, Risk>
     */
    public function risksDueForReview(?Carbon $by = null): Collection
    {
        return Risk::query()
            ->dueForReviewBy($by ?? Carbon::today())
            ->orderBy('next_review_on')
            ->get();
    }

    /**
     * Controls promised by a date that has passed, still only planned. The
     * quiet failure a risk register produces: everybody agrees on the
     * mitigation and nobody notices it was never put in.
     *
     * @return Collection<int, RiskControl>
     */
    public function overdueControls(): Collection
    {
        return RiskControl::query()
            ->overdue()
            ->with('risk')
            ->orderBy('due_on')
            ->get();
    }

    /**
     * The counters a dashboard needs, computed once.
     *
     * One call rather than five screens each deciding for themselves what
     * "overdue" means — the way two numbers on the same page come to
     * disagree.
     *
     * @return array<string, int>
     */
    public function summary(): array
    {
        $risks = Risk::query()->open()->get();

        return [
            'obligations' => ComplianceObligation::query()->active()->count(),
            'overdue' => $this->overdue()->count(),
            'due_soon' => $this->dueSoon()->count(),
            'in_progress' => ComplianceFiling::query()->open()->count(),
            'risks_open' => $risks->count(),
            'risks_severe' => $risks->filter(fn (Risk $r) => $r->inherentBand() === 'severe')->count(),
            'risks_to_review' => $this->risksDueForReview()->count(),
            'controls_overdue' => $this->overdueControls()->count(),
        ];
    }
}
