<?php

namespace App\Services\Accounting;

use App\Models\Company;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\Payslip;
use App\Support\Accounting\ChartOfAccounts;
use Illuminate\Support\Collection;

/**
 * The figures a business copies onto its monthly returns.
 *
 * ── What this is, and what it is not ─────────────────────────────────────
 *
 * It is a worksheet. Every number here is one a business has to write on a DGI
 * or CNPS form, worked out from its own books so that nobody adds up a column
 * by hand at ten to midnight on the fifteenth. It is *not* the form. The
 * official declarations have prescribed layouts, cross-references and boxes
 * that depend on a régime and a sector this software does not model, and
 * presenting a spreadsheet as a filing is a lie an accountant discovers at the
 * worst possible moment. The screen says so out loud; so does this comment,
 * because whoever extends it next will be tempted.
 *
 * ── Where the figures come from ──────────────────────────────────────────
 *
 * The TVA is read off the ledger rather than off the invoices. Those are the
 * same number when everything has been posted and different when it has not,
 * and the ledger is the record a business would be audited against — an
 * invoice issued while the chart of accounts was half configured is in the
 * sales list and not in the books, and the declaration should show what the
 * books show so that the gap is visible rather than papered over.
 *
 * The payroll figures come from the payslips, which carry their own copy of
 * the rates that produced them. Declaring March from today's rates would be
 * wrong twice over: wrong against what was actually withheld, and wrong
 * against what the employees were handed on paper.
 */
class TaxDeclarations
{
    public function __construct(protected Books $books) {}

    /**
     * The TVA position for a period.
     *
     * `due` positive is money owed to the state. Negative is a crédit de TVA —
     * more reclaimable tax than collected, which happens in any month with a
     * big purchase — and it is carried forward rather than refunded, which is
     * why it is reported as its own figure rather than as a negative payment.
     *
     * @return array{
     *     turnover: float, exempt_turnover: float,
     *     collected: float, deductible: float, due: float, credit: float,
     *     rate: float, lines: Collection<int, array<string, mixed>>
     * }
     */
    public function vat(Company $company, string $from, string $to): array
    {
        $collected = $this->movement($company, 'vat_collected', $from, $to);
        $deductible = $this->movement($company, 'vat_deductible', $from, $to);

        // Turnover is what class 7 earned in the window, net of TVA — the plan
        // keeps the tax out of income precisely so this figure is readable.
        $rows = $this->books->trialBalance($company, $from, $to)['rows'];
        $turnover = round($rows->filter(fn ($r) => $r['account']->class === 7)->sum('balance'), 2);

        $net = round($collected - $deductible, 2);

        /*
         * Sales the business made without charging TVA. Derived rather than
         * assumed: turnover that produced no tax is either genuinely exempt,
         * or an invoice somebody forgot to put a rate on — and the business
         * should see the figure either way. Computed at the company's own rate
         * because a mixed-rate business is beyond what a single line can say
         * honestly, which is why the screen shows the working.
         */
        $rate = (float) ($company->vat_rate ?: 0) / 100;
        $taxed = $rate > 0 ? round($collected / $rate, 2) : 0.0;
        $exempt = round(max(0, $turnover - $taxed), 2);

        return [
            'turnover' => $turnover,
            'taxed_turnover' => min($taxed, $turnover),
            'exempt_turnover' => $exempt,
            'collected' => $collected,
            'deductible' => $deductible,
            'due' => max(0.0, $net),
            'credit' => max(0.0, round(-$net, 2)),
            'rate' => (float) ($company->vat_rate ?: 0),
            'lines' => $this->vatDetail($company, $from, $to),
        ];
    }

    /**
     * Every entry that moved a TVA account in the window, so a figure that
     * looks wrong can be traced to the document that made it.
     *
     * @return Collection<int, array<string, mixed>>
     */
    protected function vatDetail(Company $company, string $from, string $to): Collection
    {
        $accounts = collect(['vat_collected', 'vat_deductible'])
            ->map(fn (string $role) => ChartOfAccounts::account($company, $role))
            ->filter()
            ->keyBy('id');

        if ($accounts->isEmpty()) {
            return collect();
        }

        return JournalLine::query()
            ->withoutGlobalScopes()
            ->where('journal_lines.company_id', $company->id)
            ->whereIn('ledger_account_id', $accounts->keys())
            ->whereHas('entry', function ($e) use ($from, $to) {
                $e->whereDate('entry_date', '>=', $from)->whereDate('entry_date', '<=', $to);
            })
            ->with('entry:id,entry_date,journal,reference,narration')
            ->get()
            ->map(fn (JournalLine $line) => [
                'date' => $line->entry?->entry_date,
                'journal' => $line->entry?->journal,
                'reference' => $line->entry?->reference,
                'narration' => $line->entry?->narration,
                'account' => $accounts[$line->ledger_account_id]->number ?? '',
                'kind' => ($accounts[$line->ledger_account_id]->number ?? '') === '445' ? 'deductible' : 'collected',
                // Signed so both columns read as positive amounts of tax: TVA
                // facturée is a credit, TVA récupérable is a debit.
                'amount' => round((float) $line->credit - (float) $line->debit, 2),
            ])
            ->sortBy('date')
            ->values();
    }

    /**
     * What is owed to the CNPS and the DGI on the wages paid in a period.
     *
     * Read from approved and paid runs only. A draft run is a rehearsal — its
     * figures change every time anybody edits a contract — and declaring one
     * would mean declaring a month that had not happened.
     *
     * @return array<string, mixed>
     */
    public function payroll(Company $company, string $from, string $to): array
    {
        $payslips = Payslip::query()
            ->withoutGlobalScopes()
            ->where('payslips.company_id', $company->id)
            ->whereHas('run', function ($r) use ($from, $to) {
                $r->whereIn('status', ['approved', 'paid'])
                    ->whereDate('period', '>=', $from)
                    ->whereDate('period', '<=', $to);
            })
            ->get();

        $sum = fn (string ...$fields) => round($payslips->sum(
            fn (Payslip $p) => array_sum(array_map(fn ($f) => (float) $p->{$f}, $fields))
        ), 2);

        return [
            'headcount' => $payslips->count(),
            'gross' => $sum('gross'),
            'taxable_gross' => $sum('taxable_gross'),
            'cnps_base' => $sum('cnps_base'),

            // The CNPS return: the employee's pension plus all three employer
            // contributions, which is one payment on one form.
            'cnps_employee' => $sum('cnps_employee'),
            'cnps_employer_pension' => $sum('cnps_employer_pension'),
            'cnps_employer_family' => $sum('cnps_employer_family'),
            'cnps_employer_risk' => $sum('cnps_employer_risk'),
            'cnps_total' => $sum('cnps_employee', 'cnps_employer_pension', 'cnps_employer_family', 'cnps_employer_risk'),

            // The DGI return on wages: income tax and its surcharge, the
            // housing fund from both sides, and the two local levies.
            'irpp' => $sum('irpp'),
            'cac' => $sum('cac'),
            'cfc_employee' => $sum('cfc_employee'),
            'cfc_employer' => $sum('cfc_employer'),
            'tdl' => $sum('tdl'),
            'rav' => $sum('rav'),
            'fne' => $sum('fne'),
            'dgi_total' => $sum('irpp', 'cac', 'cfc_employee', 'cfc_employer', 'tdl', 'rav', 'fne'),

            'net_paid' => $sum('net_pay'),
            'total_cost' => $sum('total_cost'),
        ];
    }

    /** An account's net movement in a window, in its own normal direction. */
    protected function movement(Company $company, string $role, string $from, string $to): float
    {
        $account = ChartOfAccounts::account($company, $role);

        if ($account === null) {
            return 0.0;
        }

        $totals = JournalLine::query()
            ->withoutGlobalScopes()
            ->where('journal_lines.company_id', $company->id)
            ->where('ledger_account_id', $account->id)
            ->whereHas('entry', function ($e) use ($from, $to) {
                $e->whereDate('entry_date', '>=', $from)->whereDate('entry_date', '<=', $to);
            })
            ->selectRaw('COALESCE(SUM(debit), 0) AS debit, COALESCE(SUM(credit), 0) AS credit')
            ->first();

        $signed = (float) $totals->debit - (float) $totals->credit;
        $balance = round($account->isDebitNormal() ? $signed : -$signed, 2);

        // Negating a zero in PHP gives -0.0, which formats as "-0" on a screen
        // whose whole subject is figures somebody is about to write on a form.
        return $balance == 0.0 ? 0.0 : $balance;
    }

    /** For the screen's header: whether the books are even ready to be declared from. */
    public function chartIsReady(Company $company): bool
    {
        return LedgerAccount::query()
            ->withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->exists();
    }
}
