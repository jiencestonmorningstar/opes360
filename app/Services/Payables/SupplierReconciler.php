<?php

namespace App\Services\Payables;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Expense;
use App\Models\SupplierStatement;
use App\Models\SupplierStatementLine;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Laying a supplier's statement beside our own record of their account.
 *
 * ── What reconciled means here ─────────────────────────────────────────────
 *
 *   their balance     what the supplier says we owe
 *   our balance       what our unpaid bills for them add up to
 *   difference        the gap, explained line by line
 *
 * The two agree when every line on both sides is accounted for. Until then the
 * difference is exactly what is unmatched, and this reports that arithmetic
 * rather than a green tick — "reconciled" with three unexplained lines is not
 * reconciled, it is hidden. It is also the direction that costs real money:
 * an unnoticed duplicate charge on a supplier statement gets paid.
 *
 * ── Suggestions, not silent matching ───────────────────────────────────────
 *
 * `autoMatch` pairs only lines that agree on both reference and amount.
 * Anything else is offered as a suggestion for a person to accept. A wrong
 * automatic match is worse than no match: it looks reconciled, so nobody ever
 * looks again, and the overcharge it papered over is paid every month after.
 */
class SupplierReconciler
{
    /** How far either side of a statement line to look for a bill. */
    public const MATCH_WINDOW_DAYS = 14;

    /**
     * Take in a statement a supplier sent.
     *
     * @param  array{statement_date: string, period_from?: ?string, period_to?: ?string, closing_balance?: float, reference?: ?string, notes?: ?string}  $header
     * @param  array<int, array{line_date: string, reference?: ?string, description?: ?string, amount: float}>  $rows
     */
    public function import(Contact $supplier, array $header, array $rows, ?User $actor = null): SupplierStatement
    {
        $company = $this->company();

        return DB::transaction(function () use ($supplier, $header, $rows, $actor, $company) {
            $statement = SupplierStatement::create([
                'company_id' => $company->id,
                'supplier_id' => $supplier->id,
                'reference' => $header['reference'] ?? null,
                'statement_date' => $header['statement_date'],
                'period_from' => $header['period_from'] ?? null,
                'period_to' => $header['period_to'] ?? null,
                'closing_balance' => round((float) ($header['closing_balance'] ?? 0), 2),
                'currency' => $company->currency ?: 'XAF',
                'status' => SupplierStatement::STATUS_OPEN,
                'notes' => $header['notes'] ?? null,
                'imported_by' => $actor?->id,
            ]);

            $this->addLines($statement, $rows);

            return $statement->load('lines');
        });
    }

    /**
     * Add lines to a statement, skipping ones already present.
     *
     * Re-importing an overlapping period has to be harmless: suppliers send
     * statements that restate the whole account every month rather than only
     * what changed, so the overlap is the normal case, not an accident.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{imported: int, skipped: int}
     */
    public function addLines(SupplierStatement $statement, array $rows): array
    {
        $existing = $statement->lines()->get()
            ->map(fn (SupplierStatementLine $line) => $line->fingerprint())
            ->flip();

        $imported = 0;
        $skipped = 0;

        DB::transaction(function () use ($rows, $statement, $existing, &$imported, &$skipped) {
            foreach ($rows as $row) {
                $line = new SupplierStatementLine([
                    'company_id' => $statement->company_id,
                    'supplier_statement_id' => $statement->id,
                    'line_date' => $row['line_date'],
                    'reference' => $row['reference'] ?? null,
                    'description' => isset($row['description']) ? trim((string) $row['description']) : null,
                    'amount' => round((float) $row['amount'], 2),
                    'status' => SupplierStatementLine::STATUS_UNMATCHED,
                ]);

                if ($existing->has($line->fingerprint())) {
                    $skipped++;

                    continue;
                }

                $line->save();
                $existing->put($line->fingerprint(), true);
                $imported++;
            }
        });

        $statement->load('lines');

        return ['imported' => $imported, 'skipped' => $skipped];
    }

    /**
     * Bills that might be this line, best first.
     *
     * @return Collection<int, Expense>
     */
    public function suggestionsFor(SupplierStatementLine $line, int $windowDays = self::MATCH_WINDOW_DAYS): Collection
    {
        $statement = $this->statementFor($line);
        $date = Carbon::parse($line->line_date);
        $amount = $line->absoluteAmount();

        return Expense::query()
            ->where('supplier_id', $statement->supplier_id)
            ->where('status', '!=', 'void')
            ->whereNotIn('id', $this->matchedExpenseIds($statement, $line))
            ->whereBetween('issue_date', [
                $date->copy()->subDays($windowDays)->toDateString(),
                $date->copy()->addDays($windowDays)->toDateString(),
            ])
            ->get()
            ->map(function (Expense $expense) use ($amount, $line, $date) {
                // Reference first, then amount, then nearness in time. A
                // reference agreeing is near-proof; an amount agreeing on its
                // own is a coincidence a business has several of every month.
                $score = 0;
                $score += $this->referencesAgree($expense->reference, $line->reference) ? 100 : 0;
                $score += abs((float) $expense->total - $amount) < 1.0 ? 50 : 0;
                $score -= min(20, abs((int) $date->diffInDays(Carbon::parse($expense->issue_date), false)));

                $expense->setAttribute('match_score', $score);

                return $expense;
            })
            ->filter(fn (Expense $expense) => $expense->getAttribute('match_score') > 0)
            ->sortByDesc(fn (Expense $expense) => $expense->getAttribute('match_score'))
            ->values();
    }

    /**
     * Pair every line whose reference AND amount agree with exactly one bill.
     *
     * @return int the number matched
     */
    public function autoMatch(SupplierStatement $statement): int
    {
        $matched = 0;

        foreach ($statement->lines()->where('status', SupplierStatementLine::STATUS_UNMATCHED)->get() as $line) {
            if ($line->reference === null || $line->reference === '') {
                continue;
            }

            $candidates = Expense::query()
                ->where('supplier_id', $statement->supplier_id)
                ->where('status', '!=', 'void')
                ->whereNotIn('id', $this->matchedExpenseIds($statement, $line))
                ->get()
                ->filter(fn (Expense $expense) => $this->referencesAgree($expense->reference, $line->reference)
                    && abs((float) $expense->total - $line->absoluteAmount()) < 1.0);

            // Exactly one, or nothing. Two bills that both fit is precisely the
            // duplicate-billing case somebody has to look at by hand.
            if ($candidates->count() !== 1) {
                continue;
            }

            $this->match($line, $candidates->first());
            $matched++;
        }

        return $matched;
    }

    public function match(SupplierStatementLine $line, Expense $expense): SupplierStatementLine
    {
        $statement = $this->statementFor($line);

        if ($expense->supplier_id !== $statement->supplier_id) {
            throw new RuntimeException('That bill belongs to a different supplier.');
        }

        if (in_array($expense->id, $this->matchedExpenseIds($statement, $line), true)) {
            throw new RuntimeException('That bill is already matched to another line on this statement.');
        }

        $line->forceFill([
            'expense_id' => $expense->id,
            'status' => SupplierStatementLine::STATUS_MATCHED,
            'matched_at' => Carbon::now(),
        ])->save();

        $this->refreshStatus($statement);

        return $line;
    }

    public function unmatch(SupplierStatementLine $line): SupplierStatementLine
    {
        $line->forceFill([
            'expense_id' => null,
            'status' => SupplierStatementLine::STATUS_UNMATCHED,
            'matched_at' => null,
        ])->save();

        $this->refreshStatus($this->statementFor($line));

        return $line;
    }

    /**
     * Mark a line as one we do not accept.
     *
     * Kept on the statement rather than deleted or matched away: a disputed
     * charge is the finding, and a business that hides it from its own
     * reconciliation will pay it the following month by default.
     */
    public function dispute(SupplierStatementLine $line, string $note): SupplierStatementLine
    {
        $line->forceFill([
            'status' => SupplierStatementLine::STATUS_DISPUTED,
            'expense_id' => null,
            'matched_at' => null,
            'note' => $note,
        ])->save();

        $this->refreshStatus($this->statementFor($line));

        return $line;
    }

    /** A line neither side needs to chase — a rounding entry, a nil charge. */
    public function ignore(SupplierStatementLine $line, ?string $note = null): SupplierStatementLine
    {
        $line->forceFill([
            'status' => SupplierStatementLine::STATUS_IGNORED,
            'note' => $note ?? $line->note,
        ])->save();

        $this->refreshStatus($this->statementFor($line));

        return $line;
    }

    /**
     * The arithmetic of the disagreement.
     *
     * @return array{
     *     their_balance: float,
     *     our_balance: float,
     *     difference: float,
     *     reconciled: bool,
     *     statement_self_consistent: bool,
     *     matched_count: int,
     *     unmatched_count: int,
     *     disputed_total: float,
     *     on_their_statement_only: array<int, array<string, mixed>>,
     *     in_our_books_only: array<int, array<string, mixed>>,
     * }
     */
    public function summary(SupplierStatement $statement): array
    {
        $lines = $statement->lines()->with('expense')->get();

        $their = round((float) $statement->closing_balance, 2);
        $ours = $this->ourBalance($statement);

        $unexplained = $lines
            ->whereIn('status', [SupplierStatementLine::STATUS_UNMATCHED, SupplierStatementLine::STATUS_DISPUTED])
            ->map(fn (SupplierStatementLine $line) => [
                'id' => $line->id,
                'line_date' => $line->line_date,
                'reference' => $line->reference,
                'description' => $line->description,
                'amount' => round((float) $line->amount, 2),
                'status' => $line->status,
            ])
            ->values()
            ->all();

        $ourUnmatched = $this->unmatchedBills($statement)
            ->map(fn (Expense $expense) => [
                'id' => $expense->id,
                'reference' => $expense->reference ?: $expense->number,
                'issue_date' => $expense->issue_date,
                'description' => $expense->description,
                'amount' => round((float) $expense->total, 2),
                'balance' => $expense->balance(),
            ])
            ->values()
            ->all();

        return [
            'their_balance' => $their,
            'our_balance' => $ours,
            // Positive means they think we owe more than we think we do, which
            // is the direction that costs money if nobody looks.
            'difference' => round($their - $ours, 2),
            // Under a franc: XAF has no minor unit, so rounding dust is agreement.
            'reconciled' => abs($their - $ours) < 1.0
                && $lines->where('status', SupplierStatementLine::STATUS_UNMATCHED)->isEmpty()
                && $ourUnmatched === [],
            'statement_self_consistent' => $statement->selfConsistent(),
            'matched_count' => $lines->where('status', SupplierStatementLine::STATUS_MATCHED)->count(),
            'unmatched_count' => $lines->where('status', SupplierStatementLine::STATUS_UNMATCHED)->count(),
            'disputed_total' => round((float) $lines
                ->where('status', SupplierStatementLine::STATUS_DISPUTED)
                ->sum(fn (SupplierStatementLine $l) => (float) $l->amount), 2),
            'on_their_statement_only' => $unexplained,
            'in_our_books_only' => $ourUnmatched,
        ];
    }

    /**
     * What our own books say is still owed to this supplier at the statement date.
     *
     * Deliberately the unpaid balance rather than everything billed in the
     * period: the supplier's closing balance is a balance, and comparing a
     * balance against a period's turnover would produce a "difference" that
     * means nothing and alarms everybody.
     */
    public function ourBalance(SupplierStatement $statement): float
    {
        return round((float) $this->openBills($statement)
            ->sum(fn (Expense $expense) => $expense->balance()), 2);
    }

    /**
     * @return Collection<int, Expense>
     */
    protected function openBills(SupplierStatement $statement): Collection
    {
        return Expense::query()
            ->where('supplier_id', $statement->supplier_id)
            ->where('status', '!=', 'void')
            ->whereColumn('amount_paid', '<', 'total')
            ->where('issue_date', '<=', $statement->statement_date)
            ->orderBy('issue_date')
            ->get()
            ->filter(fn (Expense $expense) => $expense->balance() >= 1.0)
            ->values();
    }

    /**
     * Open bills of ours that no line on their statement accounts for.
     *
     * @return Collection<int, Expense>
     */
    protected function unmatchedBills(SupplierStatement $statement): Collection
    {
        $matched = $statement->lines()
            ->whereNotNull('expense_id')
            ->pluck('expense_id')
            ->all();

        return $this->openBills($statement)
            ->reject(fn (Expense $expense) => in_array($expense->id, $matched, true))
            ->values();
    }

    /**
     * Bills already spoken for on this statement, excluding the line being
     * worked on so that re-matching a line to the same bill is not refused.
     *
     * @return array<int, string>
     */
    protected function matchedExpenseIds(SupplierStatement $statement, ?SupplierStatementLine $except = null): array
    {
        return $statement->lines()
            ->whereNotNull('expense_id')
            ->when($except !== null, fn ($q) => $q->where('id', '!=', $except->id))
            ->pluck('expense_id')
            ->all();
    }

    /**
     * References agree if they are the same once case and punctuation are set
     * aside. Suppliers write FA-101, FA 101 and fa101 for one invoice, and a
     * matcher that insists on the exact string leaves a person retyping
     * hundreds of lines and then abandoning reconciliation altogether.
     */
    protected function referencesAgree(?string $ours, ?string $theirs): bool
    {
        if ($ours === null || $theirs === null) {
            return false;
        }

        $normalise = fn (string $value) => mb_strtolower(preg_replace('/[^a-z0-9]/i', '', $value) ?? '');

        $a = $normalise($ours);
        $b = $normalise($theirs);

        return $a !== '' && $a === $b;
    }

    /**
     * The statement's own state, derived from its lines rather than set by
     * hand: a statement is only reconciled while it stays reconciled, and one
     * frozen at "reconciled" because somebody clicked a button in March is
     * worse than no status at all.
     */
    protected function refreshStatus(SupplierStatement $statement): void
    {
        $statement->load('lines');

        $status = match (true) {
            $statement->lines->contains(fn (SupplierStatementLine $l) => $l->status === SupplierStatementLine::STATUS_DISPUTED) => SupplierStatement::STATUS_DISPUTED,
            $this->summary($statement)['reconciled'] => SupplierStatement::STATUS_RECONCILED,
            default => SupplierStatement::STATUS_OPEN,
        };

        if ($statement->status !== $status) {
            $statement->forceFill(['status' => $status])->save();
        }
    }

    /**
     * Parse a supplier's CSV export.
     *
     * Deliberately forgiving about column names — every supplier's accounting
     * package exports a different shape, and a business that has to rename
     * headers in a spreadsheet before it can import will do it once and then
     * stop reconciling. Debit/credit pairs are folded into one signed amount.
     *
     * @return array<int, array<string, mixed>>
     */
    public function parseCsv(string $contents): array
    {
        $handle = fopen('php://memory', 'r+');
        fwrite($handle, $contents);
        rewind($handle);

        $header = fgetcsv($handle);

        if ($header === false) {
            fclose($handle);

            throw new RuntimeException('That file has no rows in it.');
        }

        $map = [];

        foreach ($header as $index => $name) {
            $key = mb_strtolower(trim((string) $name));
            $map[$key] = $index;
        }

        $column = function (array $names) use ($map): ?int {
            foreach ($names as $name) {
                foreach ($map as $key => $index) {
                    if (str_contains($key, $name)) {
                        return $index;
                    }
                }
            }

            return null;
        };

        $dateAt = $column(['date']);
        $refAt = $column(['reference', 'ref', 'invoice', 'facture', 'piece', 'document']);
        $descAt = $column(['description', 'details', 'libelle', 'narration', 'particulars']);
        $debitAt = $column(['debit']);
        $creditAt = $column(['credit']);
        $amountAt = $column(['amount', 'montant']);

        $rows = [];

        while (($row = fgetcsv($handle)) !== false) {
            if ($dateAt === null || ! isset($row[$dateAt]) || trim((string) $row[$dateAt]) === '') {
                continue;
            }

            /*
             * A charge is positive and a credit is negative, from the
             * supplier's point of view. When both columns exist the debit
             * column is what they billed us; where only one signed amount
             * column exists it is taken as given.
             */
            if ($debitAt !== null || $creditAt !== null) {
                $debit = $this->parseAmount($row[$debitAt] ?? null);
                $credit = $this->parseAmount($row[$creditAt] ?? null);
                $amount = round($debit - $credit, 2);
            } else {
                $amount = $this->parseAmount($amountAt === null ? null : ($row[$amountAt] ?? null));
            }

            if (abs($amount) < 0.005) {
                continue;
            }

            $rows[] = [
                'line_date' => Carbon::parse(trim((string) $row[$dateAt]))->toDateString(),
                'reference' => $refAt === null ? null : (trim((string) ($row[$refAt] ?? '')) ?: null),
                'description' => $descAt === null ? null : (trim((string) ($row[$descAt] ?? '')) ?: null),
                'amount' => $amount,
            ];
        }

        fclose($handle);

        return $rows;
    }

    protected function parseAmount(mixed $value): float
    {
        if ($value === null || trim((string) $value) === '') {
            return 0.0;
        }

        // Thousands separators and non-breaking spaces are how these files
        // arrive; (float) on "1 500 000" is 1.
        $clean = preg_replace('/[^0-9,.\-]/u', '', (string) $value) ?? '';

        // A comma with no dot is a decimal comma in francophone exports.
        if (str_contains($clean, ',') && ! str_contains($clean, '.')) {
            $clean = str_replace(',', '.', $clean);
        } else {
            $clean = str_replace(',', '', $clean);
        }

        return round((float) $clean, 2);
    }

    /**
     * The statement a line belongs to, loaded explicitly.
     *
     * `$line->statement` would be an implicit lazy load, which this
     * application disables outright — every relation has to be asked for.
     */
    protected function statementFor(SupplierStatementLine $line): SupplierStatement
    {
        $line->loadMissing('statement');

        return $line->getRelation('statement');
    }

    protected function company(): Company
    {
        $company = app(CurrentCompany::class)->get();

        if ($company === null) {
            throw new RuntimeException('Cannot reconcile a supplier without a current company.');
        }

        return $company;
    }
}
