<?php

namespace App\Services\Stock;

use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\Stocktake;
use App\Models\StocktakeLine;
use App\Models\User;
use App\Services\Accounting\Ledger;
use App\Support\Accounting\ChartOfAccounts;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The inventory: counting the shelves, and telling the books what was found.
 *
 * ── Why this is the entry that matters ───────────────────────────────────
 *
 * SYSCOHADA's ordinary presentation is the inventaire intermittent. Everything
 * bought is charged to 601 the day it is bought, which means that until
 * somebody counts, the income statement claims the business consumed every
 * crate it ever received. The count is what corrects it: the stock still on
 * the shelf is carried onto the balance sheet in 31, and the *change* in that
 * figure is posted to 6031, so
 *
 *     achats (601) − variation des stocks (6031) = the cost of what was sold
 *
 * which is the line the compte de résultat asks for and the reason gross
 * margin can be read off the books at all. Before this, 601 was the whole
 * story and every business that held stock overstated its costs.
 *
 * ── What posting does ────────────────────────────────────────────────────
 *
 * Two things, in one transaction. The movement ledger is corrected — an
 * adjustment movement per line that disagreed with the count, so stock on hand
 * afterwards is what was actually counted — and the variation is posted. Both
 * or neither: a count that moved the stock without moving the books, or the
 * reverse, is worse than no count.
 *
 * A line nobody counted is left alone. `counted_quantity` null is not zero,
 * and treating it as zero would write off every item the person doing the
 * count did not get to before closing time.
 */
class Stocktaker
{
    public function __construct(
        protected StockValuation $valuation,
        protected Ledger $ledger,
    ) {}

    /**
     * Open a count sheet: every tracked product, with what the ledger claims.
     *
     * The book quantity is frozen onto the line at this moment rather than
     * read live at posting. A count walked over an afternoon during which the
     * shop kept selling should be compared against the shelf as it was when
     * the counting started, not against a figure that moved underneath it.
     */
    public function start(
        Company $company,
        ?StockLocation $location = null,
        ?string $countedOn = null,
        ?User $actor = null,
    ): Stocktake {
        $countedOn ??= now()->toDateString();

        return DB::transaction(function () use ($company, $location, $countedOn, $actor) {
            $stocktake = Stocktake::create([
                'company_id' => $company->id,
                'stock_location_id' => $location?->id,
                'reference' => $this->nextReference($company, $countedOn),
                'counted_on' => $countedOn,
                'status' => 'draft',
                'counted_by' => $actor?->id,
            ]);

            foreach ($this->valuation->holdings($company, $location) as $row) {
                StocktakeLine::create([
                    'stocktake_id' => $stocktake->id,
                    'item_id' => $row['item']->id,
                    'book_quantity' => $row['quantity'],
                    'counted_quantity' => null,
                    'unit_cost' => $row['unit_cost'],
                ]);
            }

            return $stocktake->refresh();
        });
    }

    /**
     * Record what was counted so far.
     *
     * @param  array<string, float|string|null>  $counts  item id => counted quantity, or null/'' for "not yet"
     */
    public function save(Stocktake $stocktake, array $counts, ?User $actor = null): Stocktake
    {
        if (! $stocktake->isDraft()) {
            throw new RuntimeException('This count has been posted and cannot be changed.');
        }

        DB::transaction(function () use ($stocktake, $counts, $actor) {
            $stocktake->loadMissing('lines');

            foreach ($stocktake->lines as $line) {
                if (! array_key_exists($line->item_id, $counts)) {
                    continue;
                }

                $value = $counts[$line->item_id];

                $line->forceFill([
                    'counted_quantity' => ($value === null || $value === '')
                        ? null
                        : round((float) $value, 3),
                ])->save();
            }

            if ($actor !== null) {
                $stocktake->forceFill(['counted_by' => $actor->id])->save();
            }
        });

        return $stocktake->refresh();
    }

    /**
     * Close the count: correct the shelves, then correct the books.
     *
     * @throws RuntimeException when the count is not a draft, or nothing was counted
     */
    public function post(Stocktake $stocktake, ?User $actor = null): Stocktake
    {
        if (! $stocktake->isDraft()) {
            throw new RuntimeException('This count has already been posted.');
        }

        $company = $this->companyOf($stocktake);

        return DB::transaction(function () use ($stocktake, $company, $actor) {
            $stocktake->loadMissing('lines.item');

            $counted = $stocktake->lines->filter(fn (StocktakeLine $line) => $line->isCounted());

            if ($counted->isEmpty()) {
                throw new RuntimeException('Nothing has been counted yet, so there is nothing to post.');
            }

            foreach ($counted as $line) {
                $variance = $line->variance();

                if (abs($variance) < 0.0005) {
                    continue;
                }

                StockMovement::create([
                    'company_id' => $company->id,
                    'item_id' => $line->item_id,
                    'stock_location_id' => $stocktake->stock_location_id,
                    'quantity' => $variance,
                    // Stock found is priced at the same average as the rest, so
                    // the valuation stays consistent with itself. Stock missing
                    // carries no cost, like every other outgoing movement.
                    'unit_cost' => $variance > 0 ? (float) $line->unit_cost : null,
                    'reason' => 'stocktake',
                    'reference_type' => Stocktake::class,
                    'reference_id' => $stocktake->id,
                    'user_id' => $actor?->id,
                    'occurred_at' => $stocktake->counted_on->toDateString().' '.now()->format('H:i:s'),
                ]);
            }

            // Every line's value, counted or not: what is on the balance sheet
            // is the whole stock, and a line nobody reached is still stock.
            $closing = round($stocktake->lines->sum(fn (StocktakeLine $line) => $line->value()), 2);
            $opening = $this->stockAccountBalance($company);

            // Nothing is thrown when the chart is not ready: a business that is
            // not keeping books can still count its shelves, and the movements
            // above are the part it asked for.
            $this->postVariation($stocktake, $company, $opening, $closing, $actor);

            $stocktake->forceFill([
                'status' => 'posted',
                'posted_at' => now(),
                'opening_value' => $opening,
                'total_value' => $closing,
                'variance_value' => round($closing - $opening, 2),
                'counted_by' => $actor?->id ?? $stocktake->counted_by,
            ])->save();

            return $stocktake->refresh();
        });
    }

    /**
     * Undo a count recorded in error.
     *
     * The adjustment movements are reversed rather than deleted and the
     * journal entry is extourned rather than removed, for the same reason
     * everywhere else in this system: "what did the books say in March" has to
     * keep having an answer.
     */
    public function void(Stocktake $stocktake, ?User $actor = null): Stocktake
    {
        if ($stocktake->isVoid()) {
            throw new RuntimeException('This count is already void.');
        }

        $company = $this->companyOf($stocktake);

        return DB::transaction(function () use ($stocktake, $company, $actor) {
            if ($stocktake->isPosted()) {
                $movements = StockMovement::query()
                    ->withoutGlobalScopes()
                    ->where('company_id', $company->id)
                    ->where('reference_type', Stocktake::class)
                    ->where('reference_id', $stocktake->id)
                    ->where('reason', 'stocktake')
                    ->get();

                foreach ($movements as $movement) {
                    StockMovement::create([
                        'company_id' => $company->id,
                        'item_id' => $movement->item_id,
                        'stock_location_id' => $movement->stock_location_id,
                        'quantity' => round(-1 * (float) $movement->quantity, 3),
                        'unit_cost' => null,
                        'reason' => 'stocktake-void',
                        'reference_type' => Stocktake::class,
                        'reference_id' => $stocktake->id,
                        'user_id' => $actor?->id,
                        'occurred_at' => now(),
                    ]);
                }

                if ($entry = $this->ledger->entryFor($company, $stocktake)) {
                    $this->ledger->reverse($entry, $actor, 'Annulation inventaire '.$stocktake->reference);
                }
            }

            $stocktake->forceFill(['status' => 'void'])->save();

            return $stocktake->refresh();
        });
    }

    /**
     * Dr 31 / Cr 6031 when there is more stock than the books carried, and the
     * other way round when there is less.
     *
     * Nothing is posted when the two agree — an entry for zero is noise in a
     * journal somebody has to read — and nothing is posted when the company
     * has no chart of accounts, which is allowed.
     */
    protected function postVariation(
        Stocktake $stocktake,
        Company $company,
        float $opening,
        float $closing,
        ?User $actor,
    ): ?JournalEntry {
        $difference = round($closing - $opening, 2);

        if (abs($difference) < 0.005) {
            return null;
        }

        if (ChartOfAccounts::account($company, 'stock') === null
            || ChartOfAccounts::account($company, 'stock_variation') === null) {
            return null;
        }

        $lines = $difference > 0
            ? [
                ['account' => 'stock', 'debit' => $difference, 'narration' => 'Stock final'],
                ['account' => 'stock_variation', 'credit' => $difference],
            ]
            : [
                ['account' => 'stock_variation', 'debit' => abs($difference)],
                ['account' => 'stock', 'credit' => abs($difference), 'narration' => 'Stock final'],
            ];

        return $this->ledger->post(
            company: $company,
            journal: 'OD',
            entryDate: $stocktake->counted_on->toDateString(),
            lines: $lines,
            source: $stocktake,
            narration: 'Variation des stocks — inventaire '.$stocktake->reference,
            reference: $stocktake->reference,
            actor: $actor,
        );
    }

    /** What account 31 currently carries. Debit-normal, so debits less credits. */
    protected function stockAccountBalance(Company $company): float
    {
        $account = ChartOfAccounts::account($company, 'stock');

        if ($account === null) {
            return 0.0;
        }

        $totals = DB::table('journal_lines')
            ->where('ledger_account_id', $account->id)
            ->selectRaw('COALESCE(SUM(debit), 0) AS debit, COALESCE(SUM(credit), 0) AS credit')
            ->first();

        return round((float) $totals->debit - (float) $totals->credit, 2);
    }

    /**
     * INV-2026-0001, per company per year.
     *
     * Counted, not incremented from a column, and retried on collision: two
     * people opening a count sheet at once must not be handed the same
     * reference, and the unique index is what proves it.
     */
    protected function nextReference(Company $company, string $countedOn): string
    {
        $year = substr($countedOn, 0, 4);
        $prefix = 'INV-'.$year.'-';

        $used = Stocktake::query()
            ->withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('reference', 'like', $prefix.'%')
            ->count();

        for ($attempt = 1; $attempt <= 50; $attempt++) {
            $candidate = $prefix.str_pad((string) ($used + $attempt), 4, '0', STR_PAD_LEFT);

            $taken = Stocktake::query()
                ->withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->where('reference', $candidate)
                ->exists();

            if (! $taken) {
                return $candidate;
            }
        }

        throw new RuntimeException('Could not allocate a reference for this count.');
    }

    protected function companyOf(Stocktake $stocktake): Company
    {
        return Company::query()->findOrFail($stocktake->company_id);
    }
}
