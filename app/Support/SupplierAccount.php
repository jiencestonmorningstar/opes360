<?php

namespace App\Support;

use App\Models\Contact;
use App\Models\Expense;
use App\Models\ExpensePayment;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Our record of one supplier's account: everything that passed between the
 * business and that supplier over a period, in date order, with the balance
 * running down the page.
 *
 * The mirror of App\Support\Statement, but for the other direction, and it
 * exists for a different reason. A customer statement is something a business
 * sends out to get paid. This one is something a business reads before it pays:
 * it is what a supplier's own statement gets laid beside, and it is the answer
 * to "your account shows 4.2 million and ours shows 3.8" — a conversation that
 * otherwise costs a day of digging through a folder of delivery notes.
 *
 * ── Sign convention ────────────────────────────────────────────────────────
 *
 * Balances are what the business OWES, so they run positive when money is due
 * out. A bill credits the account, a payment debits it — the supplier's ledger
 * viewed from our side. Presenting a payable as a negative receivable would
 * make every figure on the page need a mental sign flip, and somebody would
 * eventually forget to do it.
 *
 * ── Built from totals, not balances ────────────────────────────────────────
 *
 * Each bill contributes its total and each payment contributes separately.
 * Using `total - amount_paid` would net the payment into the bill line and then
 * count it again as its own line, halving the closing balance. It also means a
 * past period stays right about that period, which a balance-based view cannot
 * be: a balance only knows about today.
 */
class SupplierAccount
{
    public function __construct(
        protected Contact $supplier,
        protected CarbonInterface $from,
        protected CarbonInterface $to,
    ) {}

    /**
     * @return array{
     *     supplier: Contact,
     *     from: CarbonInterface,
     *     to: CarbonInterface,
     *     currency: string,
     *     opening_balance: float,
     *     lines: array<int, array<string, mixed>>,
     *     closing_balance: float,
     *     totals: array{billed: float, paid: float},
     *     aging: array<string, float>,
     *     open_bills: array<int, array<string, mixed>>,
     * }
     */
    public function build(): array
    {
        $from = Carbon::parse($this->from)->startOfDay();
        $to = Carbon::parse($this->to)->endOfDay();

        $opening = $this->balanceBefore($from);
        $lines = $this->movements($from, $to);

        $balance = $opening;
        $billed = 0.0;
        $paid = 0.0;

        foreach ($lines as $index => $line) {
            $balance = round($balance + $line['credit'] - $line['debit'], 2);
            $lines[$index]['balance'] = $balance;

            $billed += $line['credit'];
            $paid += $line['debit'];
        }

        return [
            'supplier' => $this->supplier,
            'from' => $from,
            'to' => $to,
            'currency' => app(CurrentCompany::class)->get()?->currency ?? 'XAF',
            'opening_balance' => round($opening, 2),
            'lines' => $lines,
            'closing_balance' => round($balance, 2),
            'totals' => [
                'billed' => round($billed, 2),
                'paid' => round($paid, 2),
            ],
            'aging' => $this->aging($to),
            'open_bills' => $this->openBills($to),
        ];
    }

    /**
     * Everything that moved the account inside the period, oldest first.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function movements(CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = [];

        foreach ($this->bills()->whereBetween('issue_date', [$from, $to])->get() as $bill) {
            $rows[] = [
                'date' => $bill->issue_date,
                'kind' => 'bill',
                'reference' => $bill->reference ?: $bill->number,
                'description' => $bill->description,
                'debit' => 0.0,
                'credit' => round((float) $bill->total, 2),
                'expense_id' => $bill->id,
                'sequence' => $bill->created_at?->getTimestamp() ?? 0,
            ];
        }

        foreach ($this->payments()->whereBetween('paid_on', [$from, $to])->get() as $payment) {
            $rows[] = [
                'date' => $payment->paid_on,
                'kind' => 'payment',
                'reference' => $payment->reference ?: $payment->expense?->reference,
                'description' => 'Payment — '.$payment->methodLabel(),
                'debit' => round((float) $payment->amount, 2),
                'credit' => 0.0,
                'expense_id' => $payment->expense_id,
                'sequence' => $payment->created_at?->getTimestamp() ?? 0,
            ];
        }

        usort($rows, function (array $a, array $b) {
            $byDate = Carbon::parse($a['date'])->getTimestamp() <=> Carbon::parse($b['date'])->getTimestamp();

            return $byDate !== 0 ? $byDate : $a['sequence'] <=> $b['sequence'];
        });

        return array_values($rows);
    }

    /** The single figure standing at the start of the period. */
    protected function balanceBefore(CarbonInterface $from): float
    {
        $billed = (float) $this->bills()->where('issue_date', '<', $from)->sum('total');
        $paid = (float) $this->payments()->where('paid_on', '<', $from)->sum('amount');

        return round($billed - $paid, 2);
    }

    /**
     * Bills that move a supplier's account.
     *
     * Voids are excluded rather than shown at zero: a voided bill is one the
     * business says never existed, and putting it on a statement invites the
     * supplier to argue about a document neither side is claiming.
     */
    protected function bills(): Builder
    {
        return Expense::query()
            ->where('supplier_id', $this->supplier->id)
            ->where('status', '!=', 'void')
            ->orderBy('issue_date');
    }

    protected function payments(): Builder
    {
        return ExpensePayment::query()
            ->whereIn('expense_id', $this->bills()->select('id'))
            ->with('expense')
            ->orderBy('paid_on');
    }

    /**
     * How old the still-open bills are at the end of the period.
     *
     * Same buckets as the AP aging report deliberately: whoever reads this and
     * whoever reads the aging report must be looking at the same arithmetic, or
     * the conversation starts with an argument about the numbers.
     *
     * @return array<string, float>
     */
    protected function aging(CarbonInterface $to): array
    {
        $buckets = array_fill_keys(array_keys(Aging::BUCKETS), 0.0);

        foreach ($this->openBills($to) as $row) {
            $buckets[$row['bucket']] += $row['balance'];
        }

        return array_map(fn ($v) => round($v, 2), $buckets);
    }

    /**
     * What is still owed, bill by bill.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function openBills(CarbonInterface $to): array
    {
        $aging = new Aging(Carbon::parse($to)->startOfDay());

        return $this->bills()
            ->whereColumn('amount_paid', '<', 'total')
            ->where('issue_date', '<=', $to)
            ->get()
            ->map(function (Expense $bill) use ($aging) {
                $days = $aging->daysOverdue($bill->due_date ?? $bill->issue_date);

                return [
                    'id' => $bill->id,
                    'reference' => $bill->reference ?: $bill->number,
                    'issue_date' => $bill->issue_date,
                    'due_date' => $bill->due_date ?? $bill->issue_date,
                    'balance' => $bill->balance(),
                    'days_overdue' => $days,
                    'bucket' => $aging->bucketFor($days),
                ];
            })
            // XAF has no minor unit, so a franc of rounding dust is settled.
            ->filter(fn (array $row) => $row['balance'] >= 1.0)
            ->values()
            ->all();
    }
}
