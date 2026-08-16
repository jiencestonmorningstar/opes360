<?php

namespace App\Support;

use App\Models\InsuranceClaim;
use App\Models\InsurancePolicy;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What is about to go wrong with the book of business.
 *
 * The same three-list shape, kept apart for the same reason, as
 * ContractWatch: a list that mixes "act before Friday" with "it is already
 * too late" gets skimmed, and then neither gets done.
 *
 *  1. `coverLapsing()`       — cover runs out soon and renewal has not been
 *                              agreed. Somebody can still call the client.
 *  2. `lapsed()`             — cover has run out while the register still
 *                              calls the policy in force. The client may be
 *                              driving uninsured *today*; this needs a
 *                              decision, not a diary note.
 *  3. `lapsedOnAutoRenew()`  — the narrowest and the most expensive list.
 *                              The policy renews itself, its date has gone,
 *                              and nobody re-issued the schedule or invoiced
 *                              the new premium: the client is on risk for a
 *                              term nobody agreed and nobody has billed.
 *
 * The alarm this read model exists to raise is cover that lapses before
 * renewal is agreed — for a broker that is not untidiness, it is a client
 * standing uninsured and an E&O claim with the brokerage's name on it.
 *
 * Nothing is written here. Like ContractWatch and the aging report, this is a
 * photograph of a moment; the durable record is what somebody did about it,
 * and that is a renewal, a rebroke or a cancellation.
 */
class PolicyWatch
{
    public function __construct(protected ?CarbonInterface $asOf = null) {}

    public function asOf(): Carbon
    {
        return Carbon::parse($this->asOf ?? Carbon::now())->startOfDay();
    }

    /**
     * Cover ending between today and `$days` from now, soonest first.
     *
     * Inclusive at both ends — cover ending today is the most urgent row on
     * the list, not one that has already gone.
     *
     * @return Collection<int, InsurancePolicy>
     */
    public function coverLapsing(int $days = 30): Collection
    {
        $asOf = $this->asOf();

        return $this->base()
            ->whereNotNull('covers_to')
            ->whereDate('covers_to', '>=', $asOf->toDateString())
            ->whereDate('covers_to', '<=', $asOf->copy()->addDays($days)->toDateString())
            ->orderBy('covers_to')
            ->get();
    }

    /**
     * Cover already run out on policies the register still calls in force.
     *
     * Most recently lapsed first: yesterday's lapse can often still be
     * backdated by the insurer, and six months ago cannot.
     *
     * @return Collection<int, InsurancePolicy>
     */
    public function lapsed(): Collection
    {
        return $this->base()
            ->whereNotNull('covers_to')
            ->whereDate('covers_to', '<', $this->asOf()->toDateString())
            ->orderByDesc('covers_to')
            ->get();
    }

    /**
     * The subset of lapsed cover that is renewing itself — on risk, unagreed,
     * and unbilled.
     *
     * @return Collection<int, InsurancePolicy>
     */
    public function lapsedOnAutoRenew(): Collection
    {
        return $this->lapsed()->filter->autoRenews()->values();
    }

    /**
     * Renewal-notice deadlines falling inside the window, soonest first.
     *
     * @return Collection<int, InsurancePolicy>
     */
    public function noticeLapsing(int $days = 30): Collection
    {
        $asOf = $this->asOf();

        return $this->base()
            ->whereNotNull('notice_by')
            ->whereDate('notice_by', '>=', $asOf->toDateString())
            ->whereDate('notice_by', '<=', $asOf->copy()->addDays($days)->toDateString())
            ->orderBy('notice_by')
            ->get();
    }

    /**
     * Claims still open, oldest incident first — the ones a client is
     * chasing the brokerage about.
     *
     * @return Collection<int, InsuranceClaim>
     */
    public function openClaims(): Collection
    {
        return InsuranceClaim::query()
            ->whereIn('status', ['fnol', 'assessed'])
            ->with('policy.holder')
            ->orderBy('incident_on')
            ->get();
    }

    /**
     * The counts a dashboard tile shows.
     *
     * `lapsed_on_auto_renew` is deliberately a subset of `lapsed` rather than
     * an extra category — the same policy appearing in both is the point.
     *
     * @return array<string, int>
     */
    public function summary(int $window = 30): array
    {
        $lapsed = $this->lapsed();

        return [
            'cover_lapsing' => $this->coverLapsing($window)->count(),
            'lapsed' => $lapsed->count(),
            'lapsed_on_auto_renew' => $lapsed->filter->autoRenews()->count(),
            'notice_lapsing' => $this->noticeLapsing($window)->count(),
            'open_claims' => $this->openClaims()->count(),
        ];
    }

    /**
     * Only cover that is in force. A draft has not been bound and a cancelled
     * policy has been called off; warning about either is how a watchlist
     * teaches people to ignore it.
     */
    protected function base()
    {
        return InsurancePolicy::query()->live()->with(['holder', 'insurer', 'owner']);
    }
}
