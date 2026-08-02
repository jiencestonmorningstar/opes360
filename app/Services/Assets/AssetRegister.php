<?php

namespace App\Services\Assets;

use App\Models\Company;
use App\Models\DepreciationEntry;
use App\Models\FixedAsset;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Services\Accounting\Ledger;
use App\Services\Accounting\RecordsBusinessEvents;
use App\Support\Accounting\ChartOfAccounts;
use App\Support\CurrentCompany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The asset register: capitalising, depreciating and disposing.
 *
 * ── Three postings, and why each is separate ─────────────────────────────
 *
 *   capitalise   Dr 2xx / Cr cash, bank or payables. The purchase is not a
 *                cost; it converts money into a different kind of asset.
 *   depreciate   Dr 681 / Cr 28x, once a month. This is the cost, spread over
 *                the years the thing is actually used.
 *   dispose      the accumulated depreciation and the cost both leave, the
 *                remaining book value goes to 812, and the money received
 *                comes in through 822. The gain or loss is the difference —
 *                nobody computes it, it falls out.
 *
 * ── Rounding ─────────────────────────────────────────────────────────────
 *
 * Monthly charges are rounded to the franc, so 100 000 over 36 months does not
 * divide evenly. Rather than leave 12 F stranded forever, the last charge takes
 * whatever is left: the arithmetic is "what is still to write off, and how many
 * months are left", recomputed each month, not "cost ÷ life" fixed at the start.
 */
class AssetRegister
{
    public function __construct(protected RecordsBusinessEvents $books) {}

    /**
     * Record an asset and capitalise it.
     *
     * @param  array{name: string, category: string, acquired_on: string, cost: float,
     *               reference?: ?string, description?: ?string, supplier_id?: ?string,
     *               in_service_on?: ?string, residual_value?: float, method?: string,
     *               useful_life_months?: int, declining_rate?: ?float,
     *               opening_accumulated?: float, funded_by?: ?string, location?: ?string,
     *               notes?: ?string, post?: bool}  $data
     */
    public function record(array $data, ?User $actor = null): FixedAsset
    {
        $company = $this->company();

        return DB::transaction(function () use ($company, $data, $actor) {
            $category = $data['category'];

            $asset = new FixedAsset([
                'company_id' => $company->id,
                'reference' => $data['reference'] ?? null,
                'name' => trim($data['name']),
                'description' => $data['description'] ?? null,
                'category' => $category,
                'supplier_id' => $data['supplier_id'] ?? null,
                'acquired_on' => $data['acquired_on'],
                'in_service_on' => $data['in_service_on'] ?? $data['acquired_on'],
                'cost' => round((float) $data['cost'], 2),
                'residual_value' => round((float) ($data['residual_value'] ?? 0), 2),
                'currency' => $company->currency ?: 'XAF',
                'method' => $data['method'] ?? 'straight_line',
                'useful_life_months' => (int) ($data['useful_life_months'] ?? ChartOfAccounts::assetDefaultLife($category)),
                'declining_rate' => $data['declining_rate'] ?? null,
                // Already written off before this software knew about it.
                'opening_accumulated' => round((float) ($data['opening_accumulated'] ?? 0), 2),
                'accumulated_depreciation' => round((float) ($data['opening_accumulated'] ?? 0), 2),
                'status' => 'active',
                'location' => $data['location'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor?->id,
                'ledger_account_id' => $this->accountNumbered(ChartOfAccounts::assetAccountFor($category))?->id,
                'depreciation_account_id' => ($number = ChartOfAccounts::assetDepreciationAccountFor($category))
                    ? $this->accountNumbered($number)?->id
                    : null,
            ]);

            $asset->save();

            /*
             * An asset already in the books — brought forward from a previous
             * system, or capitalised out of an expense that has already been
             * posted — must not be posted a second time. `post` is how the
             * caller says which case this is.
             */
            if (($data['post'] ?? true) === true) {
                $this->postAcquisition($asset, $company, $data['funded_by'] ?? null, $actor);
            }

            return $asset;
        });
    }

    /**
     * Charge one month's depreciation on one asset.
     *
     * Idempotent per asset per period, twice over: the unique index refuses a
     * second row, and the ledger refuses a second entry for the same source.
     * Running the month again is therefore harmless, which matters because the
     * screen invites exactly that.
     */
    public function depreciate(FixedAsset $asset, string $period, ?User $actor = null): ?DepreciationEntry
    {
        $company = $this->company();
        $month = Carbon::parse($period)->startOfMonth();

        return DB::transaction(function () use ($asset, $company, $month, $actor) {
            $locked = FixedAsset::query()->lockForUpdate()->findOrFail($asset->id);
            $locked->loadMissing(['account', 'depreciationAccount']);

            if (! $locked->isDepreciable() || $locked->isDisposed()) {
                return null;
            }

            // Not yet in use, or already fully written off.
            if ($month->lt($locked->startsDepreciatingOn()) || $locked->remainingToDepreciate() <= 0.005) {
                return null;
            }

            $existing = DepreciationEntry::query()
                ->where('fixed_asset_id', $locked->id)
                ->whereDate('period', $month->toDateString())
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $amount = $this->chargeFor($locked, $month);

            if ($amount <= 0) {
                return null;
            }

            $accumulated = round((float) $locked->accumulated_depreciation + $amount, 2);

            $entry = DepreciationEntry::create([
                'company_id' => $company->id,
                'fixed_asset_id' => $locked->id,
                'period' => $month->toDateString(),
                'amount' => $amount,
                'accumulated_after' => $accumulated,
                'book_value_after' => round((float) $locked->cost - $accumulated, 2),
                'status' => 'posted',
                'created_by' => $actor?->id,
            ]);

            $locked->forceFill(['accumulated_depreciation' => $accumulated])->save();

            $this->postDepreciation($entry, $locked, $company, $month, $actor);

            return $entry;
        });
    }

    /**
     * Charge a month across every active asset.
     *
     * @return array{charged: int, total: float}
     */
    public function depreciateAll(string $period, ?User $actor = null): array
    {
        $charged = 0;
        $total = 0.0;

        FixedAsset::query()
            ->where('status', 'active')
            ->with(['account', 'depreciationAccount'])
            ->each(function (FixedAsset $asset) use ($period, $actor, &$charged, &$total) {
                $entry = $this->depreciate($asset, $period, $actor);

                if ($entry !== null && $entry->wasRecentlyCreated) {
                    $charged++;
                    $total += (float) $entry->amount;
                }
            });

        return ['charged' => $charged, 'total' => round($total, 2)];
    }

    /**
     * Sell or scrap an asset.
     *
     * The cost and its accumulated depreciation both leave the balance sheet;
     * what is left of the book value becomes a cost and the money received
     * becomes income. Nobody is asked for the gain or loss — it is the
     * difference between those two, and asking for it invites a figure that
     * disagrees with the arithmetic.
     */
    public function dispose(FixedAsset $asset, array $data, ?User $actor = null): FixedAsset
    {
        $company = $this->company();

        return DB::transaction(function () use ($asset, $company, $data, $actor) {
            $locked = FixedAsset::query()->lockForUpdate()->findOrFail($asset->id);
            $locked->loadMissing(['account', 'depreciationAccount']);

            if ($locked->isDisposed()) {
                throw new RuntimeException('This asset has already been disposed of.');
            }

            $proceeds = round((float) ($data['proceeds'] ?? 0), 2);

            if ($proceeds < 0) {
                throw new RuntimeException('Proceeds cannot be negative.');
            }

            $locked->forceFill([
                'status' => $proceeds > 0 ? 'disposed' : 'written_off',
                'disposed_on' => $data['disposed_on'] ?? now()->toDateString(),
                'disposal_proceeds' => $proceeds,
                'disposal_note' => $data['note'] ?? null,
            ])->save();

            $this->postDisposal($locked, $company, $proceeds, $data['received_by'] ?? 'bank', $actor);

            return $locked->refresh();
        });
    }

    /**
     * This month's charge.
     *
     * Straight line divides what is left by the months that are left, which is
     * the same figure as cost ÷ life for an untouched asset and the right one
     * for an asset whose life was corrected halfway through. Declining balance
     * takes its rate off the book value, and switches to the straight-line
     * remainder once that would take longer than the life allows — otherwise a
     * declining asset never quite reaches zero.
     */
    public function chargeFor(FixedAsset $asset, Carbon $month): float
    {
        $remaining = $asset->remainingToDepreciate();

        if ($remaining <= 0) {
            return 0.0;
        }

        $monthsElapsed = $asset->startsDepreciatingOn()->diffInMonths($month);
        $monthsLeft = max(1, $asset->useful_life_months - $monthsElapsed);

        if ($asset->method === 'declining' && $asset->declining_rate !== null) {
            $annual = round($asset->bookValue() * (float) $asset->declining_rate, 2);
            $charge = round($annual / 12, 2);

            // Once the declining charge falls below what is needed to finish
            // on time, finish on time.
            $charge = max($charge, round($remaining / $monthsLeft, 2));
        } else {
            $charge = round($remaining / $monthsLeft, 2);
        }

        // The last month takes whatever rounding left behind, rather than
        // stranding twelve francs on the balance sheet forever.
        return min($charge, $remaining);
    }

    /** Dr the asset account, Cr whatever paid for it. */
    protected function postAcquisition(FixedAsset $asset, Company $company, ?string $fundedBy, ?User $actor): void
    {
        $asset->loadMissing(['account', 'supplier']);

        $cost = round((float) $asset->cost, 2);

        if ($cost <= 0 || $asset->account === null) {
            return;
        }

        $this->books->recordQuietly(fn () => app(Ledger::class)->post(
            company: $company,
            journal: 'OD',
            entryDate: $asset->acquired_on->toDateString(),
            lines: [
                ['account' => $asset->account, 'debit' => $cost, 'narration' => $asset->name],
                [
                    // Null means bought on credit, which is the honest default
                    // for anything expensive enough to be an asset.
                    'account' => match ($fundedBy) {
                        'cash', 'mobile_money' => 'cash',
                        'bank', 'cheque' => 'bank',
                        default => 'payables',
                    },
                    'credit' => $cost,
                    'narration' => $asset->supplier?->displayName() ?? $asset->categoryLabel(),
                ],
            ],
            source: $asset,
            narration: 'Acquisition — '.$asset->name,
            reference: $asset->reference,
            actor: $actor,
        ));
    }

    /** Dr the charge, Cr the accumulated depreciation. */
    protected function postDepreciation(
        DepreciationEntry $entry,
        FixedAsset $asset,
        Company $company,
        Carbon $month,
        ?User $actor,
    ): void {
        if ($asset->depreciationAccount === null) {
            return;
        }

        $this->books->recordQuietly(fn () => app(Ledger::class)->post(
            company: $company,
            journal: 'OD',
            entryDate: $month->copy()->endOfMonth()->toDateString(),
            lines: [
                ['account' => 'depreciation_expense', 'debit' => (float) $entry->amount, 'narration' => $asset->name],
                ['account' => $asset->depreciationAccount, 'credit' => (float) $entry->amount, 'narration' => 'Amortissement '.$month->format('m/Y')],
            ],
            source: $entry,
            narration: 'Dotation aux amortissements — '.$asset->name,
            actor: $actor,
        ));
    }

    /**
     * Take the asset off the books and bring the money on.
     *
     * One entry, because the two halves are one event: the accumulated
     * depreciation and the cost both leave, the remaining book value lands in
     * 812, and the proceeds land in 822 against cash or bank.
     */
    protected function postDisposal(
        FixedAsset $asset,
        Company $company,
        float $proceeds,
        string $receivedBy,
        ?User $actor,
    ): void {
        if ($asset->account === null) {
            return;
        }

        $cost = round((float) $asset->cost, 2);
        $accumulated = round((float) $asset->accumulated_depreciation, 2);
        $bookValue = round($cost - $accumulated, 2);

        $lines = [];

        if ($accumulated > 0 && $asset->depreciationAccount !== null) {
            $lines[] = ['account' => $asset->depreciationAccount, 'debit' => $accumulated, 'narration' => 'Amortissements cumulés'];
        }

        if ($bookValue > 0) {
            $lines[] = ['account' => 'disposal_cost', 'debit' => $bookValue, 'narration' => 'Valeur comptable — '.$asset->name];
        }

        $lines[] = ['account' => $asset->account, 'credit' => $cost, 'narration' => $asset->name];

        if ($proceeds > 0) {
            $lines[] = [
                'account' => in_array($receivedBy, ['cash', 'mobile_money'], true) ? 'cash' : 'bank',
                'debit' => $proceeds,
                'narration' => 'Produit de cession',
            ];
            $lines[] = ['account' => 'disposal_proceeds', 'credit' => $proceeds, 'narration' => $asset->name];
        }

        $this->books->recordQuietly(fn () => app(Ledger::class)->post(
            company: $company,
            journal: 'OD',
            entryDate: $asset->disposed_on->toDateString(),
            lines: $lines,
            /*
             * No source: the asset already sources its acquisition entry, and
             * reusing it here would make the ledger swallow the disposal as a
             * duplicate of the purchase. What stops a second disposal instead
             * is the caller — dispose() re-reads the asset under a lock and
             * refuses one that has already gone.
             */
            source: null,
            narration: 'Cession — '.$asset->name,
            reference: $asset->reference,
            actor: $actor,
        ));
    }

    protected function accountNumbered(string $number): ?LedgerAccount
    {
        return LedgerAccount::query()->where('number', $number)->first();
    }

    protected function company(): Company
    {
        return app(CurrentCompany::class)->get()
            ?? throw new RuntimeException('Cannot touch the asset register without a current company.');
    }
}
