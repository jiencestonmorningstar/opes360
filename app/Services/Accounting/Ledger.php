<?php

namespace App\Services\Accounting;

use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Support\Accounting\ChartOfAccounts;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Writes to the books.
 *
 * Every entry goes through post(), which enforces the one invariant that makes
 * a ledger a ledger: debits equal credits. A system that can write an
 * unbalanced entry produces a balance sheet that does not balance, and by then
 * the bad entry is a month old and mixed in with ten thousand good ones.
 *
 * Posting is idempotent per source. An invoice re-issued after a retry, a
 * payment webhook delivered twice, a sync envelope replayed — none of them may
 * double the books, and the source morph is what makes "have I already
 * recorded this" answerable rather than a matter of hope.
 */
class Ledger
{
    /**
     * @param  array<int, array{account: string|LedgerAccount, debit?: float, credit?: float, narration?: string}>  $lines
     *                                                                                                                      `account` is either a ChartOfAccounts role or an account instance.
     */
    public function post(
        Company $company,
        string $journal,
        string $entryDate,
        array $lines,
        ?Model $source = null,
        ?string $narration = null,
        ?string $reference = null,
        ?User $actor = null,
    ): JournalEntry {
        if (! array_key_exists($journal, JournalEntry::JOURNALS)) {
            throw new RuntimeException("Unknown journal [{$journal}].");
        }

        $resolved = $this->resolve($company, $lines);

        $debit = round(array_sum(array_column($resolved, 'debit')), 2);
        $credit = round(array_sum(array_column($resolved, 'credit')), 2);

        // The half-centime tolerance is for float arithmetic, not for sloppy
        // entries: anything a rounding step could plausibly leave behind is
        // absorbed, anything larger is a bug and must not reach the books.
        if (abs($debit - $credit) >= 0.005) {
            throw new RuntimeException(
                "Refusing to post an unbalanced entry: debits {$debit}, credits {$credit}."
            );
        }

        if ($debit <= 0) {
            throw new RuntimeException('Refusing to post an entry with no value.');
        }

        return DB::transaction(function () use ($company, $journal, $entryDate, $resolved, $source, $narration, $reference, $actor) {
            if ($source !== null && $existing = $this->entryFor($company, $source)) {
                // Already recorded. Returning the original rather than throwing
                // keeps the caller's retry harmless, which is the point.
                return $existing;
            }

            $entry = JournalEntry::create([
                'company_id' => $company->id,
                'journal' => $journal,
                'entry_date' => $entryDate,
                'reference' => $reference,
                'narration' => $narration,
                'source_type' => $source ? $source::class : null,
                'source_id' => $source?->getKey(),
                'created_by' => $actor?->id,
            ]);

            foreach ($resolved as $index => $line) {
                JournalLine::create([
                    'company_id' => $company->id,
                    'journal_entry_id' => $entry->id,
                    'ledger_account_id' => $line['account']->id,
                    'debit' => $line['debit'],
                    'credit' => $line['credit'],
                    'narration' => $line['narration'] ?? null,
                    'sort_order' => $index,
                ]);
            }

            return $entry->load('lines.account');
        });
    }

    /**
     * The correcting entry for a mistake: the same lines with the sides
     * swapped. Editing the original is not offered, because "what did the
     * books say in March" has to keep having an answer.
     */
    public function reverse(JournalEntry $entry, ?User $actor = null, ?string $narration = null): JournalEntry
    {
        $company = Company::query()->findOrFail($entry->company_id);

        // Loaded explicitly: lazy loading is disabled app-wide, and reading
        // the account off each line is the whole point of a reversal.
        $entry->loadMissing('lines.account');

        $lines = $entry->lines->map(fn (JournalLine $line) => [
            'account' => $line->account,
            'debit' => (float) $line->credit,
            'credit' => (float) $line->debit,
            'narration' => $line->narration,
        ])->all();

        return DB::transaction(function () use ($company, $entry, $lines, $actor, $narration) {
            $reversal = $this->post(
                $company,
                $entry->journal,
                now()->toDateString(),
                $lines,
                null,
                $narration ?? 'Extourne de '.($entry->reference ?? $entry->id),
                $entry->reference,
                $actor,
            );

            $reversal->forceFill(['reverses_entry_id' => $entry->id])->save();

            return $reversal;
        });
    }

    /** The entry already recorded for a source, if there is one. */
    public function entryFor(Company $company, Model $source): ?JournalEntry
    {
        return JournalEntry::query()
            ->withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('source_type', $source::class)
            ->where('source_id', $source->getKey())
            ->whereNull('reverses_entry_id')
            ->first();
    }

    /**
     * Turns roles into accounts and normalises the two amount columns.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array{account: LedgerAccount, debit: float, credit: float, narration: ?string}>
     */
    protected function resolve(Company $company, array $lines): array
    {
        $resolved = [];

        foreach ($lines as $line) {
            $account = $line['account'];

            if (is_string($account)) {
                $account = ChartOfAccounts::account($company, $account)
                    ?? throw new RuntimeException(
                        "The chart of accounts has no account for [{$account}]. Seed it first."
                    );
            }

            $debit = round((float) ($line['debit'] ?? 0), 2);
            $credit = round((float) ($line['credit'] ?? 0), 2);

            // A line is one side or the other. Allowing both invites an entry
            // that nets to the right total while saying something nobody meant.
            if ($debit > 0 && $credit > 0) {
                throw new RuntimeException('A journal line cannot be both a debit and a credit.');
            }

            if ($debit < 0 || $credit < 0) {
                throw new RuntimeException('A journal line cannot be negative; swap the side instead.');
            }

            if ($debit === 0.0 && $credit === 0.0) {
                continue;
            }

            $resolved[] = [
                'account' => $account,
                'debit' => $debit,
                'credit' => $credit,
                'narration' => $line['narration'] ?? null,
            ];
        }

        if ($resolved === []) {
            throw new RuntimeException('Refusing to post an entry with no lines.');
        }

        return $resolved;
    }
}
