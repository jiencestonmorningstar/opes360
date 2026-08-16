<?php

namespace App\Support;

use App\Models\Contract;
use App\Models\ContractObligation;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What is about to go wrong with the contracts this business is bound by.
 *
 * ── Why this is the centre of the module ───────────────────────────────────
 *
 * A contract register that only stores contracts is a filing cabinet. The
 * expensive failure is not losing the paper, it is the cleaning contract that
 * renewed itself for another year at a price nobody agreed, because the last
 * day to serve notice fell three weeks before anyone thought to look at the
 * end date. Nobody did anything wrong; that is precisely the problem.
 *
 * So there are three separate lists, not one:
 *
 *  1. `noticeLapsing()`  — the deadline is coming. Somebody can still act.
 *  2. `noticeMissed()`   — the deadline has gone. This needs a decision today.
 *  3. `expiring()`       — the contract ends soon and there is no notice to
 *                          serve. Different urgency, different conversation.
 *
 * They are kept apart on the same reasoning `BusinessDocument::scopeExpiringWithin`
 * uses for expired documents: a list that mixes "act before Friday" with "it
 * is already too late" gets skimmed, and then neither gets done.
 *
 * `autoRenewingWithNoticeMissed()` is the narrowest and most valuable of them.
 * Missing a notice deadline on a contract that ends by itself is untidy;
 * missing it on one that renews itself is another year of spend that no
 * approval, budget or purchase order will ever be asked about.
 *
 * Nothing is written here. Like the aging report and the collections queue,
 * this is a photograph of a moment — the only durable record worth keeping is
 * what somebody did about it, and that is a renewal or a termination.
 */
class ContractWatch
{
    public function __construct(protected ?CarbonInterface $asOf = null) {}

    public function asOf(): Carbon
    {
        return Carbon::parse($this->asOf ?? Carbon::now())->startOfDay();
    }

    /**
     * Notice deadlines falling between today and `$days` from now, soonest
     * first.
     *
     * Inclusive at both ends. A deadline that falls today is the most urgent
     * row on the list, not one that has already gone.
     *
     * @return Collection<int, Contract>
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
     * Deadlines already gone on contracts still running.
     *
     * Most recently missed first: the ones that slipped yesterday can often
     * still be argued, and the ones from six months ago cannot.
     *
     * @return Collection<int, Contract>
     */
    public function noticeMissed(): Collection
    {
        return $this->base()
            ->whereNotNull('notice_by')
            ->whereDate('notice_by', '<', $this->asOf()->toDateString())
            ->orderByDesc('notice_by')
            ->get();
    }

    /**
     * The subset of missed deadlines that will cost money on their own.
     *
     * @return Collection<int, Contract>
     */
    public function autoRenewingWithNoticeMissed(): Collection
    {
        return $this->noticeMissed()->filter->autoRenews()->values();
    }

    /**
     * Contracts ending inside the window, soonest first.
     *
     * Already-ended ones are excluded and reported by `lapsed()` instead.
     *
     * @return Collection<int, Contract>
     */
    public function expiring(int $days = 60): Collection
    {
        $asOf = $this->asOf();

        return $this->base()
            ->whereNotNull('ends_on')
            ->whereDate('ends_on', '>=', $asOf->toDateString())
            ->whereDate('ends_on', '<=', $asOf->copy()->addDays($days)->toDateString())
            ->orderBy('ends_on')
            ->get();
    }

    /**
     * Contracts whose end date has passed while the register still calls them
     * active.
     *
     * Worth a list of its own rather than being swept into `expired`
     * automatically here: this class only reads, and a business is entitled to
     * decide that a contract it is still working under has simply not been
     * renewed on paper yet. `ContractLifecycle::expireLapsed()` is the sweep,
     * when a business wants one.
     *
     * @return Collection<int, Contract>
     */
    public function lapsed(): Collection
    {
        return $this->base()
            ->whereNotNull('ends_on')
            ->whereDate('ends_on', '<', $this->asOf()->toDateString())
            ->orderBy('ends_on')
            ->get();
    }

    /**
     * Promises past their date and still outstanding, oldest first.
     *
     * @return Collection<int, ContractObligation>
     */
    public function overdueObligations(): Collection
    {
        return ContractObligation::query()
            ->overdue($this->asOf())
            ->with('contract')
            ->orderBy('due_on')
            ->get();
    }

    /**
     * The counts a dashboard tile shows.
     *
     * `auto_renewing_at_risk` is deliberately a subset of `notice_missed`
     * rather than an extra category — the same contract appearing in both is
     * the point, because the second number says how many of the first are
     * about to charge the business for the oversight.
     *
     * @return array<string, int>
     */
    public function summary(int $noticeWindow = 30, int $expiryWindow = 60): array
    {
        $missed = $this->noticeMissed();

        return [
            'notice_lapsing' => $this->noticeLapsing($noticeWindow)->count(),
            'notice_missed' => $missed->count(),
            'auto_renewing_at_risk' => $missed->filter->autoRenews()->count(),
            'expiring' => $this->expiring($expiryWindow)->count(),
            'lapsed' => $this->lapsed()->count(),
            'overdue_obligations' => $this->overdueObligations()->count(),
        ];
    }

    /**
     * Only contracts that still bind anybody.
     *
     * A draft has not been agreed and a terminated one has been called off;
     * warning about either is how a watchlist teaches people to ignore it.
     */
    protected function base()
    {
        return Contract::query()->live()->with(['counterparty', 'owner']);
    }
}
