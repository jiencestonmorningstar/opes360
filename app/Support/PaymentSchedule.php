<?php

namespace App\Support;

use App\Models\Company;
use App\Models\Expense;
use App\Services\Banking\CashForecast;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Which supplier bills to pay, in what order, and how far this week's cash gets.
 *
 * The AP aging report already says which bills are late. What it cannot say is
 * which of them the money actually reaches — and a business that pays bills in
 * the order they surface pays a small supplier on Tuesday and then cannot make
 * payroll on Friday. This is the other half of the answer.
 *
 * The mirror of App\Support\CollectionsQueue, and deliberately not the same
 * calculation. Collections ranks by who is most likely to stop paying. Payables
 * ranks by what it costs the business not to pay: a bill 120 days late is a
 * supplier about to stop delivering, which shuts the business down long before
 * the debt itself does.
 *
 * ── Bands, then a score inside each ────────────────────────────────────────
 *
 *  1. Overdue. Money the business already said it would have paid.
 *  2. Due inside the horizon. Payable now to stay in terms.
 *  3. Everything else, shown so nobody is surprised by it, funded last.
 *
 * Inside a band the score is the amount weighted by lateness, so the same
 * money that has been outstanding longest is paid first. Unlike collections
 * the weighting is gentle: a supplier's patience does not fall off a cliff at
 * ninety days the way a customer's willingness to pay does, and a steep curve
 * here would starve every recent bill to clear one ancient dispute.
 *
 * Nothing here is stored. Like the aging report this is a photograph of a
 * moment; the thing worth persisting is the decision a human made from it,
 * which is App\Models\PaymentRun.
 */
class PaymentSchedule
{
    public const BAND_OVERDUE = 3;

    public const BAND_DUE_SOON = 2;

    public const BAND_LATER = 1;

    /** Age multipliers applied to the outstanding amount, by aging bucket. */
    public const AGE_WEIGHTS = [
        'current' => 1.0,
        '1_30' => 1.5,
        '31_60' => 2.0,
        '61_90' => 2.5,
        'over_90' => 3.0,
    ];

    public const DECISION_FUND = 'fund';

    public const DECISION_PART = 'part';

    public const DECISION_DEFER = 'defer';

    /**
     * @param  int  $horizonDays  How far ahead counts as "due soon".
     * @param  float  $reserve  Cash the plan may not touch — payroll, tax, a float.
     */
    public function __construct(
        protected Company $company,
        protected ?CarbonInterface $payOn = null,
        protected float $reserve = 0.0,
        protected int $horizonDays = 14,
    ) {}

    public function payOn(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->payOn ?? CarbonImmutable::now())->startOfDay();
    }

    public function reserve(): float
    {
        return round(max(0.0, $this->reserve), 2);
    }

    /**
     * Every unpaid bill, banded and scored, worst first.
     *
     * Built on Aging::payable() rather than its own query over expenses. Two
     * implementations of "what is outstanding" would eventually disagree, and
     * the aging report is the one the business already trusts.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function bills(): Collection
    {
        $asOf = $this->payOn();
        $payable = (new Aging($asOf))->payable();

        // The aging report carries our own number, not the supplier's invoice
        // reference — and the supplier's is the one anybody arguing about a
        // payment will quote back down the phone.
        $references = Expense::query()
            ->whereIn('id', $payable['rows']->flatMap(fn (array $p) => collect($p['items'] ?? [])->pluck('id'))->all())
            ->pluck('reference', 'id');

        return $payable['rows']
            ->flatMap(function (array $party) use ($asOf, $references) {
                return collect($party['items'] ?? [])->map(function (array $item) use ($party, $asOf, $references) {
                    $due = CarbonImmutable::parse($item['due_date'])->startOfDay();
                    $days = (int) $item['days_overdue'];
                    $amount = round((float) $item['amount'], 2);

                    $band = match (true) {
                        $days > 0 => self::BAND_OVERDUE,
                        $due->diffInDays($asOf, false) >= -$this->horizonDays => self::BAND_DUE_SOON,
                        default => self::BAND_LATER,
                    };

                    return [
                        'expense_id' => $item['id'],
                        'number' => $item['number'] ?? null,
                        'reference' => $references[$item['id']] ?? $item['number'] ?? null,
                        'supplier_id' => $party['party_id'],
                        'supplier' => $party['party'],
                        'due_date' => $due,
                        'days_overdue' => $days,
                        'bucket' => $item['bucket'],
                        'outstanding' => $amount,
                        'band' => $band,
                        'priority' => round($amount * (self::AGE_WEIGHTS[$item['bucket']] ?? 1.0), 2),
                    ];
                });
            })
            ->sort(function (array $a, array $b) {
                return [$b['band'], $b['priority']] <=> [$a['band'], $a['priority']];
            })
            ->values();
    }

    /**
     * The plan: how far the cash gets down that list.
     *
     * @param  float|null  $cash  Overrides the forecast. Businesses know about
     *                            money the ledger does not — an owner's
     *                            injection, a cheque already in the drawer.
     * @return array{
     *     pay_on: string,
     *     cash_on_hand: float,
     *     reserve: float,
     *     cash_available: float,
     *     items: array<int, array<string, mixed>>,
     *     total_scheduled: float,
     *     total_outstanding: float,
     *     shortfall: float,
     *     suppliers_paid: int,
     * }
     */
    public function plan(?float $cash = null): array
    {
        $onHand = $cash === null ? $this->projectedCash() : round($cash, 2);
        $available = round(max(0.0, $onHand - $this->reserve()), 2);

        $remaining = $available;
        $items = [];
        $scheduled = 0.0;
        $outstanding = 0.0;
        $suppliers = [];

        foreach ($this->bills() as $bill) {
            $outstanding += $bill['outstanding'];

            $take = min($bill['outstanding'], $remaining);

            /*
             * Part-funding the bill the cash runs out on, rather than skipping
             * to a smaller one that fits. Leaving money idle while a supplier
             * waits for the whole amount helps nobody, and a part payment is
             * what a business would do on the phone anyway. The floor is one
             * franc: XAF has no minor unit, so a smaller "payment" is noise.
             */
            $decision = match (true) {
                $take < 1.0 => self::DECISION_DEFER,
                $take >= $bill['outstanding'] - 0.005 => self::DECISION_FUND,
                default => self::DECISION_PART,
            };

            if ($decision === self::DECISION_DEFER) {
                $take = 0.0;
            }

            $remaining = round($remaining - $take, 2);
            $scheduled += $take;

            if ($take > 0) {
                $suppliers[$bill['supplier_id']] = true;
            }

            $items[] = $bill + [
                'scheduled' => round($take, 2),
                'decision' => $decision,
                'deferred' => round($bill['outstanding'] - $take, 2),
            ];
        }

        return [
            'pay_on' => $this->payOn()->toDateString(),
            'cash_on_hand' => $onHand,
            'reserve' => $this->reserve(),
            'cash_available' => $available,
            'items' => $items,
            'total_scheduled' => round($scheduled, 2),
            'total_outstanding' => round($outstanding, 2),
            // What the business still owes after this run. The number a plan
            // exists to shrink, and the one nobody looks at if it is not shown.
            'shortfall' => round($outstanding - $scheduled, 2),
            'suppliers_paid' => count($suppliers),
        ];
    }

    /**
     * Cash the business can expect to have in hand on the pay date.
     *
     * Taken from the forecast rather than from the bank balance alone, so that
     * a run scheduled for the end of the month can spend the receipts due
     * before it. Deliberately only the receipts side of the forecast: the
     * payments side is precisely what this schedule is deciding, and letting
     * the forecast subtract them here would count every bill twice — once as a
     * forecast outflow and again as a line in the plan — leaving a business
     * that could pay convinced it could not.
     */
    public function projectedCash(): float
    {
        $forecast = app(CashForecast::class);
        $from = CarbonImmutable::now()->startOfWeek();
        $payWeek = $this->payOn()->startOfWeek();

        $weeks = max(1, (int) $from->diffInWeeks($payWeek) + 1);

        $projection = $forecast->project($this->company, $weeks, $from);

        $receipts = $projection['weeks']
            ->filter(fn (array $week) => CarbonImmutable::parse($week['starts_on'])->lte($payWeek))
            ->sum('receipts');

        return round((float) $projection['opening'] + (float) $receipts, 2);
    }
}
