<?php

namespace App\Services\Accounting;

use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Support\Accounting\ChartOfAccounts;
use Illuminate\Support\Collection;

/**
 * Reads the books. Nothing here writes — every figure is derived from
 * journal_lines, which is the only place a balance is allowed to come from.
 *
 * That constraint is the point. A cached balance column drifts the first time
 * an entry is written outside the one code path that maintains it, and the
 * drift is invisible until an accountant adds up a column by hand. Summing the
 * lines is slower and always right.
 *
 * Every method takes a window. SYSCOHADA statements are period statements, and
 * a report that silently means "everything ever" is the wrong answer to
 * "what did we earn in March".
 */
class Books
{
    /**
     * The trial balance — every account with its movements and its balance.
     * The whole thing must foot: total debits equal total credits, or an entry
     * got in that should not have.
     *
     * @return array{
     *     rows: Collection<int, array<string, mixed>>,
     *     total_debit: float, total_credit: float, balanced: bool
     * }
     */
    public function trialBalance(Company $company, ?string $from = null, ?string $to = null): array
    {
        $totals = $this->lineQuery($company, $from, $to)
            ->selectRaw('ledger_account_id, SUM(debit) as debit, SUM(credit) as credit')
            ->groupBy('ledger_account_id')
            ->get()
            ->keyBy('ledger_account_id');

        $rows = $this->accounts($company)
            ->map(function (LedgerAccount $account) use ($totals) {
                $debit = (float) ($totals[$account->id]->debit ?? 0);
                $credit = (float) ($totals[$account->id]->credit ?? 0);

                return [
                    'account' => $account,
                    'debit' => $debit,
                    'credit' => $credit,
                    // Signed in the account's own direction, so a receivable
                    // reads positive when money is owed to the business and a
                    // payable reads positive when the business owes it.
                    'balance' => $account->isDebitNormal() ? $debit - $credit : $credit - $debit,
                ];
            })
            // Accounts that never moved in the window are noise on a balance.
            ->filter(fn (array $row) => $row['debit'] != 0.0 || $row['credit'] != 0.0)
            ->values();

        $totalDebit = round($rows->sum('debit'), 2);
        $totalCredit = round($rows->sum('credit'), 2);

        return [
            'rows' => $rows,
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'balanced' => abs($totalDebit - $totalCredit) < 0.005,
        ];
    }

    /**
     * The grand livre for one account: every movement in order, with the
     * balance carried forward line by line — which is how anyone actually
     * checks a ledger, by finding the line where it stopped agreeing.
     *
     * @return array{opening: float, lines: Collection<int, array<string, mixed>>, closing: float}
     */
    public function accountLedger(Company $company, LedgerAccount $account, ?string $from = null, ?string $to = null): array
    {
        $opening = 0.0;

        if ($from !== null) {
            $before = $this->lineQuery($company, null, null)
                ->where('ledger_account_id', $account->id)
                ->whereHas('entry', fn ($q) => $q->where('entry_date', '<', $from))
                ->selectRaw('COALESCE(SUM(debit),0) as debit, COALESCE(SUM(credit),0) as credit')
                ->first();

            $opening = $account->isDebitNormal()
                ? (float) $before->debit - (float) $before->credit
                : (float) $before->credit - (float) $before->debit;
        }

        $running = $opening;

        $lines = $this->lineQuery($company, $from, $to)
            ->where('ledger_account_id', $account->id)
            ->with('entry')
            ->get()
            ->sortBy([
                fn ($a, $b) => $a->entry->entry_date <=> $b->entry->entry_date,
                fn ($a, $b) => $a->created_at <=> $b->created_at,
            ])
            ->values()
            ->map(function (JournalLine $line) use (&$running, $account) {
                $movement = $account->isDebitNormal()
                    ? (float) $line->debit - (float) $line->credit
                    : (float) $line->credit - (float) $line->debit;

                $running = round($running + $movement, 2);

                return [
                    'line' => $line,
                    'entry' => $line->entry,
                    'debit' => (float) $line->debit,
                    'credit' => (float) $line->credit,
                    'balance' => $running,
                ];
            });

        return ['opening' => round($opening, 2), 'lines' => $lines, 'closing' => $running];
    }

    /**
     * Compte de résultat — class 7 produits less class 6 charges, plus the
     * hors activités ordinaires in class 8.
     *
     * The HAO half is kept in its own keys rather than folded into the two
     * totals, because that is the distinction the statement exists to draw: a
     * shop that broke even trading and posted a profit only because it sold a
     * van has had a bad year, and a single "profit" figure would hide it. The
     * `resultat` includes both, because that is what the business is actually
     * left with — and because the balance sheet's two sides only meet if it
     * does.
     *
     * @return array{produits: Collection, charges: Collection, hao_produits: Collection, hao_charges: Collection, total_produits: float, total_charges: float, total_hao_produits: float, total_hao_charges: float, resultat_exploitation: float, resultat: float}
     */
    public function incomeStatement(Company $company, ?string $from = null, ?string $to = null): array
    {
        $balance = $this->trialBalance($company, $from, $to)['rows'];

        $produits = $balance->filter(fn ($r) => $r['account']->class === 7)->values();
        $charges = $balance->filter(fn ($r) => $r['account']->class === 6)->values();

        // Class 8 holds both sides; only the account's normal side says which.
        $haoProduits = $balance->filter(fn ($r) => $r['account']->class === 8 && ! $r['account']->isDebitNormal())->values();
        $haoCharges = $balance->filter(fn ($r) => $r['account']->class === 8 && $r['account']->isDebitNormal())->values();

        $totalProduits = round($produits->sum('balance'), 2);
        $totalCharges = round($charges->sum('balance'), 2);
        $totalHaoProduits = round($haoProduits->sum('balance'), 2);
        $totalHaoCharges = round($haoCharges->sum('balance'), 2);

        // Positive is a profit. Charges are debit-normal so their balance is
        // already positive as a cost; subtracting is the whole sum.
        $exploitation = round($totalProduits - $totalCharges, 2);

        return [
            'produits' => $produits,
            'charges' => $charges,
            'hao_produits' => $haoProduits,
            'hao_charges' => $haoCharges,
            'total_produits' => $totalProduits,
            'total_charges' => $totalCharges,
            'total_hao_produits' => $totalHaoProduits,
            'total_hao_charges' => $totalHaoCharges,
            'resultat_exploitation' => $exploitation,
            'resultat' => round($exploitation + $totalHaoProduits - $totalHaoCharges, 2),
        ];
    }

    /**
     * Bilan — a summary by class, not the official filing form.
     *
     * SYSCOHADA's bilan has a fixed line structure with prescribed captions and
     * cross-references to the notes. This groups the same figures by class so a
     * business can see its position; producing the filing document itself needs
     * the official template, and presenting this as that document would be a
     * lie an accountant discovers at the worst moment.
     *
     * @return array{actif: Collection, passif: Collection, total_actif: float, total_passif: float, resultat: float, balanced: bool}
     */
    public function balanceSheet(Company $company, ?string $from = null, ?string $to = null): array
    {
        $rows = $this->trialBalance($company, $from, $to)['rows'];
        $resultat = $this->incomeStatement($company, $from, $to)['resultat'];

        // Classes 2, 3 and 5 are assets outright. Class 4 splits: an account
        // holding what is owed *to* the business is an asset, one holding what
        // the business owes is a liability, and only its normal side says which.
        $actif = $rows->filter(fn ($r) => in_array($r['account']->class, [2, 3, 5], true)
            || ($r['account']->class === 4 && $r['account']->isDebitNormal()))->values();

        $passif = $rows->filter(fn ($r) => $r['account']->class === 1
            || ($r['account']->class === 4 && ! $r['account']->isDebitNormal()))->values();

        $totalActif = round($actif->sum('balance'), 2);
        // The period's result belongs to the owners, so it sits on the passif
        // side — which is what makes the two sides meet.
        $totalPassif = round($passif->sum('balance') + $resultat, 2);

        return [
            'actif' => $actif,
            'passif' => $passif,
            'total_actif' => $totalActif,
            'total_passif' => $totalPassif,
            'resultat' => $resultat,
            'balanced' => abs($totalActif - $totalPassif) < 0.005,
        ];
    }

    /**
     * The journal listing — entries in date order, optionally one journal.
     *
     * @return Collection<int, JournalEntry>
     */
    public function journal(Company $company, ?string $journal = null, ?string $from = null, ?string $to = null): Collection
    {
        return JournalEntry::query()
            ->withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->when($journal, fn ($q) => $q->where('journal', $journal))
            ->when($from, fn ($q) => $q->whereDate('entry_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('entry_date', '<=', $to))
            ->with(['lines.account'])
            ->orderBy('entry_date')
            ->orderBy('created_at')
            ->get();
    }

    /** @return Collection<int, LedgerAccount> */
    public function accounts(Company $company): Collection
    {
        return LedgerAccount::query()
            ->withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->orderBy('number')
            ->get();
    }

    public function classes(): array
    {
        return ChartOfAccounts::CLASSES;
    }

    /**
     * Lines within the window. Scopes are dropped and the company pinned by
     * hand because these reports run for the current tenant and, from the admin
     * side, for a company the reader is not a member of.
     */
    protected function lineQuery(Company $company, ?string $from, ?string $to)
    {
        return JournalLine::query()
            ->withoutGlobalScopes()
            ->where('journal_lines.company_id', $company->id)
            ->when($from || $to, fn ($q) => $q->whereHas('entry', function ($e) use ($from, $to) {
                $from && $e->whereDate('entry_date', '>=', $from);
                $to && $e->whereDate('entry_date', '<=', $to);
            }));
    }
}
