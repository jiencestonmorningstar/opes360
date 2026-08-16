<?php

namespace App\Support;

use App\Models\CrmActivity;
use App\Models\Deal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * What the open pipeline is likely worth, month by month and person by person.
 *
 * A pure read over the deals that already exist, in the style of Aging and
 * CashForecast: nothing is stored, so the forecast can never disagree with the
 * board it summarises. Weight is the standard one — amount × win probability —
 * with the probability coming off the stage unless somebody set a better
 * number on the deal itself.
 *
 * Buckets are computed in PHP off the cast date, deliberately. A date cast
 * stores midnight, and a naive whereBetween on month bounds silently drops
 * every deal expected on the last day of the month — the exact rows a
 * month-end forecast is asked about.
 */
class SalesForecast
{
    public function __construct(
        protected int $months = 6,
        protected ?CarbonImmutable $from = null,
    ) {}

    public function from(): CarbonImmutable
    {
        return ($this->from ?? CarbonImmutable::now())->startOfMonth();
    }

    /**
     * @return array{
     *     months: Collection<int, array{month: string, label: string, weighted: float, gross: float, count: int}>,
     *     unscheduled: array{weighted: float, gross: float, count: int},
     *     owners: Collection<int, array{owner_id: ?int, owner: string, weighted: float, gross: float, count: int}>,
     *     total_weighted: float,
     *     total_gross: float
     * }
     */
    public function build(): array
    {
        $from = $this->from();
        $deals = Deal::query()->open()->with('owner')->get();

        $months = collect(range(0, $this->months - 1))
            ->map(fn (int $i) => $from->addMonths($i))
            ->mapWithKeys(fn (CarbonImmutable $m) => [$m->format('Y-m') => [
                'month' => $m->format('Y-m'),
                'label' => $m->format('M Y'),
                'weighted' => 0.0,
                'gross' => 0.0,
                'count' => 0,
            ]]);

        $unscheduled = ['weighted' => 0.0, 'gross' => 0.0, 'count' => 0];
        $owners = [];
        $totalWeighted = 0.0;
        $totalGross = 0.0;

        foreach ($deals as $deal) {
            $gross = (float) $deal->value;
            $weighted = round($gross * $deal->winProbability() / 100, 2);

            $totalGross += $gross;
            $totalWeighted += $weighted;

            $key = $this->bucketFor($deal, $from);

            if ($key === null) {
                $unscheduled['weighted'] += $weighted;
                $unscheduled['gross'] += $gross;
                $unscheduled['count']++;
            } elseif ($months->has($key)) {
                $months = $months->put($key, array_merge($months[$key], [
                    'weighted' => $months[$key]['weighted'] + $weighted,
                    'gross' => $months[$key]['gross'] + $gross,
                    'count' => $months[$key]['count'] + 1,
                ]));
            }
            // A close date beyond the horizon stays in the totals but has no
            // month row — the horizon is a viewport, not a filter on truth.

            $ownerKey = $deal->owner_id ?? 0;
            $owners[$ownerKey] ??= [
                'owner_id' => $deal->owner_id,
                'owner' => $deal->owner?->name ?? 'Unassigned',
                'weighted' => 0.0,
                'gross' => 0.0,
                'count' => 0,
            ];
            $owners[$ownerKey]['weighted'] += $weighted;
            $owners[$ownerKey]['gross'] += $gross;
            $owners[$ownerKey]['count']++;
        }

        return [
            'months' => $months->values()->map(fn (array $m) => array_merge($m, [
                'weighted' => round($m['weighted'], 2),
                'gross' => round($m['gross'], 2),
            ])),
            'unscheduled' => array_merge($unscheduled, [
                'weighted' => round($unscheduled['weighted'], 2),
                'gross' => round($unscheduled['gross'], 2),
            ]),
            'owners' => collect($owners)->sortByDesc('weighted')->values(),
            'total_weighted' => round($totalWeighted, 2),
            'total_gross' => round($totalGross, 2),
        ];
    }

    /**
     * The deals nobody has been near: open, no activity logged inside the
     * window, and the record itself not written to either. The single most
     * useful CRM query there is — a pipeline dies of neglect, not of losses.
     *
     * @return Builder<Deal>
     */
    public function untouchedDeals(int $days = 7): Builder
    {
        $cutoff = now()->subDays($days);

        return Deal::query()
            ->open()
            ->where('updated_at', '<', $cutoff)
            ->whereDoesntHave('activities', fn (Builder $q) => $q->where(
                (new CrmActivity)->getTable().'.created_at', '>=', $cutoff
            ))
            ->orderBy('updated_at');
    }

    /**
     * Which month an open deal's money belongs to. An overdue close date rolls
     * forward to the current month — "expected last month and still open" does
     * not mean the money vanished, it means the soonest honest answer is now.
     * No date at all is the unscheduled bucket, surfaced rather than dropped.
     */
    protected function bucketFor(Deal $deal, CarbonImmutable $from): ?string
    {
        if ($deal->expected_close_on === null) {
            return null;
        }

        $month = CarbonImmutable::parse($deal->expected_close_on->toDateString())->startOfMonth();

        return $month->lessThan($from) ? $from->format('Y-m') : $month->format('Y-m');
    }
}
