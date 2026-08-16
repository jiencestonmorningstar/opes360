<?php

namespace App\Services\Banking;

use App\Models\Company;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Support\Accounting\ChartOfAccounts;
use App\Support\Aging;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * What the bank balance is likely to look like over the coming weeks.
 *
 * Built from what the business has already committed to rather than from a
 * trend: money customers owe on the dates they owe it, money the business
 * owes on the dates it falls due. A forecast extrapolated from last month's
 * average would be a guess dressed as a projection, and the one question this
 * answers — "can I make payroll on the 28th" — deserves better than a guess.
 *
 * Reuses App\Support\Aging for both sides rather than re-querying invoices
 * and bills. Two implementations of "what is outstanding" would eventually
 * disagree, and the aging report is the one a business already trusts.
 */
class CashForecast
{
    /**
     * @return array{
     *     opening: float,
     *     weeks: Collection<int, array{starts_on: string, receipts: float, payments: float, net: float, closing: float}>,
     *     lowest: float,
     *     goes_negative: bool
     * }
     */
    public function project(Company $company, int $weeks = 8, ?CarbonImmutable $from = null): array
    {
        $from ??= CarbonImmutable::now()->startOfWeek();
        $opening = $this->currentCashPosition($company);

        $aging = new Aging;
        $receivables = $this->byWeek($this->flatten($aging->receivable()['rows']), $from, $weeks);
        $payables = $this->byWeek($this->flatten($aging->payable()['rows']), $from, $weeks);

        $running = $opening;
        $lowest = $opening;

        $rows = collect(range(0, $weeks - 1))->map(function (int $index) use ($from, $receivables, $payables, &$running, &$lowest) {
            $weekStart = $from->addWeeks($index);
            $key = $weekStart->toDateString();

            $receipts = round($receivables[$key] ?? 0.0, 2);
            $payments = round($payables[$key] ?? 0.0, 2);
            $net = round($receipts - $payments, 2);

            $running = round($running + $net, 2);
            $lowest = min($lowest, $running);

            return [
                'starts_on' => $key,
                'receipts' => $receipts,
                'payments' => $payments,
                'net' => $net,
                'closing' => $running,
            ];
        });

        return [
            'opening' => round($opening, 2),
            'weeks' => $rows,
            'lowest' => round($lowest, 2),
            // The single fact worth surfacing: a business that will run out
            // of money in week 5 needs to know now, not in week 5.
            'goes_negative' => $lowest < 0,
        ];
    }

    /** What is actually in cash and bank right now, from the ledger. */
    public function currentCashPosition(Company $company): float
    {
        $accountIds = LedgerAccount::query()
            ->withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->whereIn('number', [
                ChartOfAccounts::ROLES['cash'][0],
                ChartOfAccounts::ROLES['bank'][0],
            ])
            ->pluck('id');

        if ($accountIds->isEmpty()) {
            return 0.0;
        }

        return (float) JournalLine::query()
            ->whereIn('ledger_account_id', $accountIds)
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as net')
            ->value('net');
    }

    /**
     * Aging groups by party and nests the individual invoices under `items`,
     * because that is the shape an aging report is read in. A forecast wants
     * the invoices themselves — each has its own due date, and a party's
     * total says nothing about when its money arrives.
     *
     * @param  Collection<int, array<string, mixed>>  $partyRows
     * @return Collection<int, array<string, mixed>>
     */
    protected function flatten(Collection $partyRows): Collection
    {
        return $partyRows->flatMap(fn (array $party) => $party['items'] ?? []);
    }

    /**
     * Buckets outstanding rows into the week their money is expected.
     *
     * Anything already overdue is placed in the first week rather than in the
     * past week it was due — a forecast is about what is still to come, and
     * an invoice three months late is money the business is still chasing
     * now. It is deliberately not dropped: pretending overdue money will
     * never arrive would understate the position as badly as assuming it
     * arrives on time overstates it.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, float>
     */
    protected function byWeek(Collection $rows, CarbonImmutable $from, int $weeks): array
    {
        $lastWeekStart = $from->addWeeks($weeks - 1);
        $buckets = [];

        foreach ($rows as $row) {
            $due = CarbonImmutable::parse($row['due_date']);

            // Overdue money lands in the first week: it is being chased now.
            // Not "now plus a few days" — adding a week's grace from a Monday
            // silently pushes it into the second week, which is a different
            // claim than the one intended and a worse one, since it makes
            // this week look emptier than the business's own chasing implies.
            if ($due->lt($from)) {
                $due = $from;
            }

            // Beyond the horizon is outside the question being asked.
            if ($due->gt($lastWeekStart->endOfWeek())) {
                continue;
            }

            $key = $due->startOfWeek()->toDateString();
            $buckets[$key] = ($buckets[$key] ?? 0.0) + (float) $row['amount'];
        }

        return $buckets;
    }
}
