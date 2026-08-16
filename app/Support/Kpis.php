<?php

namespace App\Support;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Document;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Project;
use App\Models\WorkflowInstance;
use App\Services\Accounting\Books;
use App\Services\Banking\CashForecast;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The executive KPI set — one period, its predecessor, and the gap between.
 *
 * ── The one rule ───────────────────────────────────────────────────────────
 *
 * This class computes nothing the books already answer. Every figure is read
 * through the report that owns it — revenue through the same query the sales
 * report runs, net result through Books::incomeStatement, cash through the
 * forecast's own position, receivables through the aging report. A KPI card
 * that disagrees with the report a click away is worse than no card: it costs
 * the reader their trust in both numbers, and trust is the only thing an
 * executive screen sells.
 *
 * What it deliberately does NOT produce:
 *
 *  - Gross margin. COGS is not posted to the books when an invoice is issued,
 *    and deriving it here from today's weighted-average stock cost would
 *    restate history every time a supplier's price moved. A margin that
 *    changes retroactively is a lie with a percent sign.
 *  - Pipeline weighted value. No sales-forecast read model exists yet; when
 *    one does, this class repeats its figure rather than inventing its own.
 *
 * Like Aging and ContractWatch, nothing is stored: a KPI is a photograph of a
 * moment, and a stored one is wrong by the time anybody opens it.
 */
class Kpis
{
    /** How far ahead a payable counts as needing money set aside. */
    public const PAYABLE_HORIZON_DAYS = 14;

    public function __construct(
        protected Company $company,
        protected CarbonImmutable $from,
        protected CarbonImmutable $to,
    ) {}

    /**
     * @return array{
     *     period: array<string, float|int|null>,
     *     previous: array<string, float|int|null>,
     *     deltas: array<string, float|null>,
     *     now: array<string, float|int>
     * }
     */
    public function summary(): array
    {
        [$prevFrom, $prevTo] = $this->previousWindow();

        $period = $this->windowed($this->from, $this->to);
        $previous = $this->windowed($prevFrom, $prevTo);

        return [
            'period' => $period,
            'previous' => $previous,
            'deltas' => collect($period)
                ->map(fn ($value, string $key) => $this->delta((float) $value, (float) ($previous[$key] ?? 0)))
                ->all(),
            'now' => $this->now(),
        ];
    }

    /**
     * The figures that belong to a window of time.
     *
     * @return array<string, float|int|null>
     */
    protected function windowed(CarbonImmutable $from, CarbonImmutable $to): array
    {
        // Bounded on start-of-day and end-of-day throughout: issue_date is a
        // date cast that stores midnight, so a bare range comparison silently
        // drops everything issued on the last day of the period.
        $start = $from->startOfDay();
        $end = $to->endOfDay();

        // The same query the sales report (Reports\Index) runs, so the card
        // and the report cannot drift apart.
        $revenue = (float) Document::query()->invoices()->issuedBetween($start, $end)->sum('total');
        $count = (int) Document::query()->invoices()->issuedBetween($start, $end)->count();

        $collected = (float) Payment::query()->whereBetween('received_at', [$start, $end])->sum('amount');

        // Mirrors the home dashboard's expense counter: recorded bills, voids
        // excluded, TTC — "what did we spend" is what left the account.
        $expenses = (float) Expense::query()
            ->where('status', '!=', 'void')
            ->whereBetween('issue_date', [$start, $end])
            ->sum('total');

        return [
            'revenue' => round($revenue, 2),
            'invoices' => $count,
            'collected' => round($collected, 2),
            'expenses' => round($expenses, 2),
            // The books' own answer, not documents minus expenses — those are
            // different accounting bases and only the ledger's is the result.
            'net_result' => app(Books::class)
                ->incomeStatement($this->company, $start->toDateString(), $end->toDateString())['resultat'],
            'average_days_to_payment' => $this->averageDaysToPayment($start, $end),
        ];
    }

    /**
     * The figures that only exist as of right now — balances, not flows.
     * A running balance never resets with the date filter, and pretending it
     * does is how a "last month" view ends up claiming money moved that didn't.
     *
     * @return array<string, float|int>
     */
    protected function now(): array
    {
        $aging = (new Aging)->receivable();
        $overdue = round($aging['total'] - $aging['totals']['current'], 2);

        // The payment schedule's own banding: overdue plus due inside the
        // horizon is exactly what its "fund now" bands cover.
        $dueSoon = (new PaymentSchedule($this->company, horizonDays: self::PAYABLE_HORIZON_DAYS))
            ->bills()
            ->filter(fn (array $bill) => $bill['band'] >= PaymentSchedule::BAND_DUE_SOON)
            ->sum('outstanding');

        return [
            'cash_position' => app(CashForecast::class)->currentCashPosition($this->company),
            'receivables_outstanding' => $aging['total'],
            'receivables_overdue' => $overdue,
            'receivables_overdue_share' => $aging['total'] > 0
                ? round($overdue / $aging['total'] * 100, 1)
                : 0.0,
            'payables_due_soon' => round($dueSoon, 2),
        ];
    }

    /**
     * How long customers take to pay, weighted by money rather than by count.
     *
     * Weighted because the question behind the number is about cash, not
     * paperwork: ten small invoices paid overnight and one large one paid at
     * ninety days is a slow-paying book, and a simple average would call it
     * fast. Read from payment allocations, since one payment can settle
     * several invoices with different ages.
     */
    protected function averageDaysToPayment(CarbonImmutable $from, CarbonImmutable $to): ?float
    {
        $payments = Payment::query()
            ->whereBetween('received_at', [$from, $to])
            ->with('allocations.document')
            ->get();

        $weighted = 0.0;
        $total = 0.0;

        foreach ($payments as $payment) {
            foreach ($payment->allocations as $allocation) {
                $issued = $allocation->document?->issue_date;

                if ($issued === null) {
                    continue;
                }

                $days = max(0, (int) $issued->startOfDay()
                    ->diffInDays($payment->received_at->copy()->startOfDay(), false));

                $weighted += (float) $allocation->amount * $days;
                $total += (float) $allocation->amount;
            }
        }

        return $total > 0 ? round($weighted / $total, 1) : null;
    }

    /**
     * Who the revenue actually came from, best first.
     *
     * Deliberately revenue and collection, not margin: cost is not recorded
     * against customers anywhere in the platform, and a "profit by customer"
     * built by spreading overheads would be an allocation argument dressed as
     * a report. Revenue, what was collected, and what is still owed are the
     * three facts the documents genuinely know.
     *
     * @return Collection<int, array{contact: Contact, revenue: float, outstanding: float, invoices: int}>
     */
    public function customerProfitability(int $limit = 10): Collection
    {
        $totals = Document::query()
            ->invoices()
            ->issuedBetween($this->from->startOfDay(), $this->to->endOfDay())
            ->whereNotNull('contact_id')
            ->selectRaw('contact_id, SUM(total) as revenue, SUM(balance) as outstanding, COUNT(*) as documents')
            ->groupBy('contact_id')
            ->orderByDesc('revenue')
            ->limit($limit)
            ->get()
            ->keyBy('contact_id');

        if ($totals->isEmpty()) {
            return collect();
        }

        return Contact::query()
            ->whereIn('id', $totals->keys())
            ->get()
            ->map(fn (Contact $contact) => [
                'contact' => $contact,
                'revenue' => (float) $totals[$contact->id]->revenue,
                'outstanding' => (float) $totals[$contact->id]->outstanding,
                'invoices' => (int) $totals[$contact->id]->documents,
            ])
            ->sortByDesc('revenue')
            ->values();
    }

    /**
     * Open projects against their budgets — consumption, not profit.
     *
     * Documents carry no project link, so revenue per project cannot be read
     * from anywhere that already exists; what a project honestly knows is its
     * budget and its cost to date, and the useful executive fact is which ones
     * are eating theirs fastest.
     *
     * @return Collection<int, array{project: Project, budget: float, cost: float, consumed_share: float|null}>
     */
    public function projectBudgets(int $limit = 8): Collection
    {
        return Project::query()
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->whereNotNull('budget')
            ->with(['contact'])
            ->orderByDesc('budget')
            ->limit($limit)
            ->get()
            ->map(function (Project $project) {
                $cost = round($project->costToDate(), 2);
                $budget = (float) $project->budget;

                return [
                    'project' => $project,
                    'budget' => $budget,
                    'cost' => $cost,
                    'consumed_share' => $budget > 0 ? round($cost / $budget * 100, 1) : null,
                ];
            })
            ->sortByDesc(fn (array $row) => $row['consumed_share'] ?? -1)
            ->values();
    }

    /** Approvals stuck with no path forward — the workflow engine's own word. */
    public function stalledApprovals(): int
    {
        return WorkflowInstance::query()->where('status', 'stalled')->count();
    }

    /**
     * The same length of time, immediately before. Length-matched by days so
     * a custom range compares fairly, not calendar-month-shifted.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    protected function previousWindow(): array
    {
        $days = (int) $this->from->startOfDay()->diffInDays($this->to->startOfDay());
        $prevTo = $this->from->subDay();

        return [$prevTo->subDays($days), $prevTo];
    }

    /** Percentage change, or null when there is nothing to compare against. */
    protected function delta(float $current, float $previous): ?float
    {
        if ($previous == 0.0) {
            return null;
        }

        return round(($current - $previous) / abs($previous) * 100, 1);
    }
}
