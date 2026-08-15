<?php

namespace App\Support;

use App\Models\Contact;
use App\Models\Document;
use App\Models\Expense;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Who owes the business money, who the business owes, and how late each is.
 *
 * The two questions a business actually asks at month end — "who do I chase?"
 * and "what must I pay this week?" — and the platform could not answer either.
 * It knew the total outstanding; it did not know how old it was, which is the
 * part that decides what anyone does about it.
 *
 * A pure read over balances that already exist. Nothing is stored: an aging
 * report is a photograph of a moment, and a stored one is wrong by the time
 * anybody opens it.
 *
 * ── Buckets ────────────────────────────────────────────────────────────────
 *
 * Measured from the due date, not the issue date. An invoice issued in January
 * on ninety-day terms is not overdue in February, and a report that says it is
 * trains people to ignore it.
 *
 * A document with no due date is treated as due on its issue date. That is the
 * conservative reading — it surfaces the invoice rather than parking it in
 * "current" forever — and this platform lets you issue without terms.
 */
class Aging
{
    /**
     * Bucket floors in days past due. `null` is the not-yet-due bucket.
     *
     * Thirty-day steps because that is what every accountant, bank and credit
     * insurer expects to see, and an aging report's whole value is being
     * comparable to the one beside it.
     */
    public const BUCKETS = [
        'current' => 'Not yet due',
        '1_30' => '1–30 days',
        '31_60' => '31–60 days',
        '61_90' => '61–90 days',
        'over_90' => 'Over 90 days',
    ];

    public function __construct(protected ?CarbonInterface $asOf = null) {}

    public function asOf(): CarbonInterface
    {
        return $this->asOf ?? Carbon::now()->startOfDay();
    }

    /**
     * Money owed TO the business, by customer.
     *
     * @return array{rows: Collection, totals: array<string, float>, total: float}
     */
    public function receivable(): array
    {
        $rows = Document::query()
            ->invoices()
            ->outstanding()
            ->with('contact')
            ->get()
            ->map(fn (Document $d) => [
                'id' => $d->id,
                'number' => $d->number,
                'party_id' => $d->contact_id,
                'party' => $d->contact?->displayName() ?? 'Unknown customer',
                'issue_date' => $d->issue_date,
                'due_date' => $d->due_date ?? $d->issue_date,
                'amount' => (float) $d->balance,
            ]);

        return $this->summarise($rows);
    }

    /**
     * Money the business owes, by supplier.
     *
     * @return array{rows: Collection, totals: array<string, float>, total: float}
     */
    public function payable(): array
    {
        $rows = Expense::query()
            ->where('status', '!=', 'void')
            ->whereColumn('amount_paid', '<', 'total')
            ->with('supplier')
            ->get()
            ->map(fn (Expense $e) => [
                'id' => $e->id,
                'number' => $e->number,
                'party_id' => $e->supplier_id,
                'party' => $e->supplier?->displayName() ?? 'Unknown supplier',
                'issue_date' => $e->issue_date,
                'due_date' => $e->due_date ?? $e->issue_date,
                'amount' => round((float) $e->total - (float) $e->amount_paid, 2),
            ])
            // Rounding can leave a fraction of a franc on a fully-settled bill.
            // XAF has no minor unit, so anything under one franc is settled.
            ->filter(fn (array $r) => $r['amount'] >= 1.0)
            ->values();

        return $this->summarise($rows);
    }

    /**
     * Group rows by party, bucket each one, and total the lot.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array{rows: Collection, totals: array<string, float>, total: float}
     */
    protected function summarise(Collection $rows): array
    {
        $empty = array_fill_keys(array_keys(self::BUCKETS), 0.0);

        $byParty = $rows
            ->groupBy('party_id')
            ->map(function (Collection $partyRows) use ($empty) {
                $buckets = $empty;
                $items = [];

                foreach ($partyRows as $row) {
                    $days = $this->daysOverdue($row['due_date']);
                    $bucket = $this->bucketFor($days);

                    $buckets[$bucket] += $row['amount'];

                    $items[] = $row + ['days_overdue' => $days, 'bucket' => $bucket];
                }

                // Oldest first: the report exists to point at the worst debt,
                // and burying it under recent invoices defeats the purpose.
                usort($items, fn ($a, $b) => $b['days_overdue'] <=> $a['days_overdue']);

                return [
                    'party_id' => $partyRows->first()['party_id'],
                    'party' => $partyRows->first()['party'],
                    'buckets' => array_map(fn ($v) => round($v, 2), $buckets),
                    'total' => round(array_sum($buckets), 2),
                    'oldest_days' => $items[0]['days_overdue'],
                    'items' => $items,
                ];
            })
            ->sortByDesc('total')
            ->values();

        $totals = $empty;

        foreach ($byParty as $party) {
            foreach ($party['buckets'] as $key => $value) {
                $totals[$key] += $value;
            }
        }

        return [
            'rows' => $byParty,
            'totals' => array_map(fn ($v) => round($v, 2), $totals),
            'total' => round(array_sum($totals), 2),
        ];
    }

    /** Negative when the due date has not arrived. */
    public function daysOverdue(mixed $dueDate): int
    {
        if ($dueDate === null) {
            return 0;
        }

        $due = $dueDate instanceof CarbonInterface ? $dueDate : Carbon::parse((string) $dueDate);

        // Whole days between calendar dates, so a bill due today reads as 0
        // rather than as a fraction that rounds unpredictably either way.
        return (int) $due->copy()->startOfDay()->diffInDays($this->asOf()->copy()->startOfDay(), false);
    }

    public function bucketFor(int $daysOverdue): string
    {
        return match (true) {
            $daysOverdue <= 0 => 'current',
            $daysOverdue <= 30 => '1_30',
            $daysOverdue <= 60 => '31_60',
            $daysOverdue <= 90 => '61_90',
            default => 'over_90',
        };
    }

    /** One customer's or supplier's outstanding detail, for a profile page. */
    public function forParty(Contact $contact, string $side = 'receivable'): array
    {
        $report = $side === 'payable' ? $this->payable() : $this->receivable();

        return $report['rows']->firstWhere('party_id', $contact->id) ?? [
            'party_id' => $contact->id,
            'party' => $contact->displayName(),
            'buckets' => array_fill_keys(array_keys(self::BUCKETS), 0.0),
            'total' => 0.0,
            'oldest_days' => 0,
            'items' => [],
        ];
    }
}
