<?php

namespace App\Services\Banking;

use App\Models\BankAccount;
use App\Models\BankStatementLine;
use App\Models\Company;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Services\Accounting\Ledger;
use App\Services\Accounting\RecordsBusinessEvents;
use App\Support\CurrentCompany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Laying the bank statement beside the books.
 *
 * ── What reconciled means here ───────────────────────────────────────────
 *
 *   book balance        what the ledger account says
 *   statement balance   what the bank says
 *   unmatched in/out    the lines on either side nobody has paired up
 *
 * The two balances agree when everything is matched. Until then the difference
 * is exactly the unmatched movements, and the screen shows that arithmetic
 * rather than a green tick — because "reconciled" with three unexplained lines
 * is not reconciled, it is hidden.
 *
 * ── Suggestions, not automatic matching ──────────────────────────────────
 *
 * `suggestionsFor` proposes candidates by amount and nearby date. It does not
 * apply them. A wrong automatic match is worse than no match: it looks
 * reconciled, so nobody ever looks again, and the two errors it papered over
 * stay in the books for a year.
 */
class Reconciler
{
    public function __construct(protected RecordsBusinessEvents $books) {}

    /**
     * Import statement lines.
     *
     * Re-importing an overlapping period is harmless: a line already present
     * with the same date, amount, reference and description is skipped. Banks
     * rarely let you export "everything since last time", so the overlap is
     * the normal case rather than an accident.
     *
     * @param  array<int, array{value_date: string, description: string, amount: float, reference?: ?string, running_balance?: ?float}>  $rows
     * @return array{imported: int, skipped: int}
     */
    public function import(BankAccount $account, array $rows, ?User $actor = null): array
    {
        $company = $this->company();
        $batch = (string) Carbon::now()->format('YmdHis');

        $existing = BankStatementLine::query()
            ->where('bank_account_id', $account->id)
            ->get()
            ->map(fn (BankStatementLine $line) => $line->fingerprint())
            ->flip();

        $imported = 0;
        $skipped = 0;

        DB::transaction(function () use ($rows, $account, $company, $batch, $existing, &$imported, &$skipped) {
            foreach ($rows as $row) {
                $line = new BankStatementLine([
                    'company_id' => $company->id,
                    'bank_account_id' => $account->id,
                    'value_date' => $row['value_date'],
                    'description' => trim($row['description']),
                    'reference' => $row['reference'] ?? null,
                    'amount' => round((float) $row['amount'], 2),
                    'running_balance' => isset($row['running_balance']) ? round((float) $row['running_balance'], 2) : null,
                    'status' => 'unmatched',
                    'import_batch' => $batch,
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

        return ['imported' => $imported, 'skipped' => $skipped];
    }

    /**
     * Parse a bank's CSV export.
     *
     * Deliberately forgiving about column names — every Cameroonian bank
     * exports a different shape, and a business that has to rename headers in
     * a spreadsheet before it can import will do it once and then stop
     * reconciling. Debit/credit pairs are folded into one signed amount.
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

        $columns = array_map(
            fn ($name) => preg_replace('/[^a-z]/', '', mb_strtolower((string) $name)),
            $header
        );

        $find = function (array $candidates) use ($columns): ?int {
            foreach ($candidates as $candidate) {
                $index = array_search($candidate, $columns, true);

                if ($index !== false) {
                    return $index;
                }
            }

            return null;
        };

        $dateAt = $find(['date', 'valuedate', 'datedevaleur', 'dateoperation', 'transactiondate']);
        $descriptionAt = $find(['description', 'libelle', 'label', 'narration', 'details', 'motif']);
        $amountAt = $find(['amount', 'montant', 'value']);
        $debitAt = $find(['debit', 'withdrawal', 'retrait', 'sortie']);
        $creditAt = $find(['credit', 'deposit', 'versement', 'entree']);
        $referenceAt = $find(['reference', 'ref', 'numero', 'piece']);
        $balanceAt = $find(['balance', 'solde', 'runningbalance']);

        if ($dateAt === null || ($amountAt === null && $debitAt === null && $creditAt === null)) {
            fclose($handle);

            throw new RuntimeException(
                'This file needs at least a date column and either an amount column or a debit and credit pair.'
            );
        }

        $rows = [];

        while (($record = fgetcsv($handle)) !== false) {
            if ($record === [null] || $record === []) {
                continue;
            }

            $date = $this->parseDate($record[$dateAt] ?? null);

            if ($date === null) {
                continue;
            }

            $amount = $amountAt !== null
                ? $this->parseAmount($record[$amountAt] ?? null)
                // Money out is negative, whichever column the bank put it in.
                : $this->parseAmount($record[$creditAt] ?? null) - $this->parseAmount($record[$debitAt] ?? null);

            if (abs($amount) < 0.005) {
                continue;
            }

            $rows[] = [
                'value_date' => $date,
                'description' => trim((string) ($descriptionAt !== null ? ($record[$descriptionAt] ?? '') : '')) ?: 'Bank movement',
                'reference' => $referenceAt !== null ? (trim((string) ($record[$referenceAt] ?? '')) ?: null) : null,
                'amount' => $amount,
                'running_balance' => $balanceAt !== null ? $this->parseAmount($record[$balanceAt] ?? null) : null,
            ];
        }

        fclose($handle);

        if ($rows === []) {
            throw new RuntimeException('No usable rows were found in that file.');
        }

        return $rows;
    }

    /**
     * Journal lines on this bank's account that no statement line claims yet.
     *
     * @return Collection<int, JournalLine>
     */
    public function unmatchedBookLines(BankAccount $account, ?string $upTo = null): Collection
    {
        $claimed = BankStatementLine::query()
            ->where('bank_account_id', $account->id)
            ->whereNotNull('journal_entry_id')
            ->pluck('journal_entry_id');

        $from = $account->opened_on?->toDateString();

        return JournalLine::query()
            ->where('ledger_account_id', $account->ledger_account_id)
            ->whereNotIn('journal_entry_id', $claimed)
            // Everything up to the opening date was settled when the line was
            // drawn under it; re-litigating it is what makes a first
            // reconciliation impossible.
            ->when($from, fn ($q) => $q->whereHas('entry', fn ($e) => $e->whereDate('entry_date', '>', $from)))
            ->when($upTo, fn ($q) => $q->whereHas('entry', fn ($e) => $e->whereDate('entry_date', '<=', $upTo)))
            ->with('entry')
            ->get();
    }

    /**
     * Candidate book lines for a statement line: same amount, same direction,
     * within a few days. Ordered by how close the dates are.
     *
     * @return Collection<int, JournalLine>
     */
    public function suggestionsFor(BankStatementLine $line, int $windowDays = 7): Collection
    {
        $account = $line->bankAccount;

        if ($account === null) {
            return collect();
        }

        $amount = $line->absoluteAmount();

        return $this->unmatchedBookLines($account)
            ->filter(function (JournalLine $book) use ($line, $amount, $windowDays) {
                // Money into the bank is a debit in the books, and out is a
                // credit — the statement's sign and the ledger's side have to
                // agree or the "match" is two unrelated movements.
                $bookAmount = $line->isCredit() ? (float) $book->debit : (float) $book->credit;

                if (abs($bookAmount - $amount) >= 0.005) {
                    return false;
                }

                return abs($book->entry->entry_date->diffInDays($line->value_date)) <= $windowDays;
            })
            ->sortBy(fn (JournalLine $book) => abs($book->entry->entry_date->diffInDays($line->value_date)))
            ->values();
    }

    /** Record that a statement line and a journal entry are the same event. */
    public function match(BankStatementLine $line, JournalLine $book, ?User $actor = null): BankStatementLine
    {
        return DB::transaction(function () use ($line, $book, $actor) {
            $locked = BankStatementLine::query()->lockForUpdate()->findOrFail($line->id);

            if ($locked->isMatched()) {
                throw new RuntimeException('That line has already been matched.');
            }

            $taken = BankStatementLine::query()
                ->where('journal_entry_id', $book->journal_entry_id)
                ->where('id', '!=', $locked->id)
                ->exists();

            if ($taken) {
                throw new RuntimeException('Another statement line is already matched to that entry.');
            }

            $locked->forceFill([
                'status' => 'matched',
                'journal_entry_id' => $book->journal_entry_id,
                'matched_at' => now(),
                'matched_by' => $actor?->id,
            ])->save();

            return $locked;
        });
    }

    public function unmatch(BankStatementLine $line): BankStatementLine
    {
        $line->forceFill([
            'status' => 'unmatched',
            'journal_entry_id' => null,
            'matched_to_type' => null,
            'matched_to_id' => null,
            'matched_at' => null,
            'matched_by' => null,
        ])->save();

        return $line;
    }

    /**
     * Turn an unmatched statement line into a journal entry, and match it.
     *
     * The useful half of a reconciliation: bank charges, interest and standing
     * orders reach the books only this way, because nobody in the business
     * ever sees them happen.
     */
    public function recordFromStatement(
        BankStatementLine $line,
        string $counterAccountNumber,
        ?User $actor = null,
        ?string $narration = null,
    ): BankStatementLine {
        $company = $this->company();

        return DB::transaction(function () use ($line, $counterAccountNumber, $company, $actor, $narration) {
            $locked = BankStatementLine::query()->with('bankAccount.account')->lockForUpdate()->findOrFail($line->id);

            if ($locked->isMatched()) {
                throw new RuntimeException('That line has already been matched.');
            }

            $bankAccount = $locked->bankAccount?->account;

            if ($bankAccount === null) {
                throw new RuntimeException('This bank account is not linked to a ledger account yet.');
            }

            $counter = LedgerAccount::query()->where('number', $counterAccountNumber)->first();

            if ($counter === null) {
                throw new RuntimeException("The chart of accounts has no account numbered {$counterAccountNumber}.");
            }

            $amount = $locked->absoluteAmount();

            $entry = $this->books->recordQuietly(fn () => app(Ledger::class)->post(
                company: $company,
                journal: 'BQ',
                entryDate: $locked->value_date->toDateString(),
                lines: $locked->isCredit()
                    ? [
                        ['account' => $bankAccount, 'debit' => $amount, 'narration' => $locked->description],
                        ['account' => $counter, 'credit' => $amount, 'narration' => $locked->description],
                    ]
                    : [
                        ['account' => $counter, 'debit' => $amount, 'narration' => $locked->description],
                        ['account' => $bankAccount, 'credit' => $amount, 'narration' => $locked->description],
                    ],
                source: $locked,
                narration: $narration ?? $locked->description,
                reference: $locked->reference,
                actor: $actor,
            ));

            $locked->forceFill([
                'status' => 'matched',
                'journal_entry_id' => $entry?->id,
                'matched_at' => now(),
                'matched_by' => $actor?->id,
            ])->save();

            return $locked;
        });
    }

    /** A line the business has decided needs no entry — a duplicate, a reversal. */
    public function ignore(BankStatementLine $line, ?string $note = null): BankStatementLine
    {
        $line->forceFill(['status' => 'ignored', 'note' => $note])->save();

        return $line;
    }

    /**
     * The reconciliation, as arithmetic rather than a verdict.
     *
     * `reconciled` means every line the bank sent has been accounted for and
     * nothing is left unexplained. It deliberately does NOT require
     * `unmatched_book` to be zero: a cheque written but not yet presented is a
     * normal reconciling item, not an error, and a month that refused to close
     * until the payee banked it would never close. The figure is reported
     * separately so it stays visible rather than being quietly absorbed.
     *
     * @return array{book_balance: float, statement_balance: float, unmatched_in: float, unmatched_out: float, unmatched_book: float, difference: float, reconciled: bool, unmatched_count: int}
     */
    public function summary(BankAccount $account, ?string $upTo = null): array
    {
        $upTo ??= $account->statement_date?->toDateString();
        $from = $account->opened_on?->toDateString();

        /*
         * The book balance is the whole of the ledger account up to the
         * statement date — the opening line only says which movements are
         * still open for matching, not which ones count towards the balance.
         */
        $movements = (float) JournalLine::query()
            ->where('ledger_account_id', $account->ledger_account_id)
            ->when($from, fn ($q) => $q->whereHas('entry', fn ($e) => $e->whereDate('entry_date', '>', $from)))
            ->when($upTo, fn ($q) => $q->whereHas('entry', fn ($e) => $e->whereDate('entry_date', '<=', $upTo)))
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) AS net')
            ->value('net');

        $bookBalance = $from === null
            ? $movements
            : round((float) $account->opening_balance + $movements, 2);

        $unmatched = BankStatementLine::query()
            ->where('bank_account_id', $account->id)
            ->where('status', 'unmatched')
            ->when($from, fn ($q) => $q->whereDate('value_date', '>', $from))
            ->when($upTo, fn ($q) => $q->whereDate('value_date', '<=', $upTo))
            ->get();

        $unmatchedIn = round((float) $unmatched->where('amount', '>', 0)->sum('amount'), 2);
        $unmatchedOut = round((float) abs($unmatched->where('amount', '<', 0)->sum('amount')), 2);

        // Money the books know about that the bank has not shown yet — an
        // uncleared cheque is the classic.
        $unmatchedBook = round(
            $this->unmatchedBookLines($account, $upTo)
                ->sum(fn (JournalLine $l) => (float) $l->debit - (float) $l->credit),
            2
        );

        $statement = round((float) $account->statement_balance, 2);

        /*
         * The books, adjusted for everything the bank has recorded and we have
         * not, less everything we have recorded and the bank has not, should
         * equal the statement. Whatever is left over is a real discrepancy.
         */
        $expected = round($bookBalance + $unmatchedIn - $unmatchedOut - $unmatchedBook, 2);

        return [
            'book_balance' => round($bookBalance, 2),
            'statement_balance' => $statement,
            'unmatched_in' => $unmatchedIn,
            'unmatched_out' => $unmatchedOut,
            'unmatched_book' => $unmatchedBook,
            'difference' => round($statement - $expected, 2),
            'reconciled' => abs($statement - $expected) < 0.005 && $unmatched->isEmpty(),
            'unmatched_count' => $unmatched->count(),
        ];
    }

    /** Dates come out of banks in every order anybody has ever thought of. */
    protected function parseDate(mixed $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        /*
         * \DateTime rather than Carbon: Carbon 3 throws on a string that does
         * not fit the format, so a loop over candidate formats would blow up on
         * the first miss instead of trying the next one.
         */
        foreach (['d/m/Y', 'd-m-Y', 'Y-m-d', 'd/m/y', 'd.m.Y', 'm/d/Y'] as $format) {
            $date = \DateTime::createFromFormat('!'.$format, $value);

            if ($date !== false && $date->format($format) === $value) {
                return $date->format('Y-m-d');
            }
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /** "1 250 000,50", "1,250,000.50" and "(1250)" all mean something. */
    protected function parseAmount(mixed $value): float
    {
        $value = trim((string) $value);

        if ($value === '') {
            return 0.0;
        }

        // Accounting parentheses are a minus sign.
        $negative = str_starts_with($value, '(') && str_ends_with($value, ')');
        $value = trim($value, '()');

        $value = preg_replace('/[^\d,.\-]/u', '', $value) ?? '';

        // Whichever separator comes last is the decimal one.
        $lastComma = strrpos($value, ',');
        $lastDot = strrpos($value, '.');

        if ($lastComma !== false && ($lastDot === false || $lastComma > $lastDot)) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        } else {
            $value = str_replace(',', '', $value);
        }

        $amount = (float) $value;

        return round($negative ? -$amount : $amount, 2);
    }

    protected function company(): Company
    {
        return app(CurrentCompany::class)->get()
            ?? throw new RuntimeException('Cannot reconcile without a current company.');
    }
}
