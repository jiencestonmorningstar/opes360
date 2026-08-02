<?php

namespace App\Services\Stock;

use App\Models\Company;
use App\Models\Item;
use App\Models\StockLocation;
use App\Models\StockMovement;
use Illuminate\Support\Collection;

/**
 * What the stock is worth.
 *
 * ── The method ───────────────────────────────────────────────────────────
 *
 * Coût unitaire moyen pondéré de fin de période — the weighted average of
 * everything that came in, applied to everything still there. It is one of the
 * two methods the AUDCIF allows (the other is FIFO, épuisement successif), and
 * it is the one that survives contact with a shop: it needs no lot tracking
 * and gives the same answer whichever crate the shopkeeper reached into.
 *
 * A business that paid 400 for twenty in March and 550 for thirty in July
 * holds stock at 490, not at either price. Costs come off the movements
 * themselves rather than off the item, because the item's `cost` is what the
 * next one is expected to cost — a planning figure that changes when the
 * supplier's price does, and that would silently restate history if the books
 * were built on it.
 *
 * ── The fallback ─────────────────────────────────────────────────────────
 *
 * Most businesses will start with neither: movements recorded before this
 * existed carry no cost, and a shop that has never typed a cost price has
 * nothing to average. Those fall back to the item's cost, and then to zero.
 * Zero is honest — stock whose cost nobody has ever recorded genuinely cannot
 * be valued — and `itemsWithoutCost()` exists so a screen can say so out loud
 * rather than quietly reporting an inventory worth nothing.
 */
class StockValuation
{
    /**
     * The weighted average cost of one unit, keyed by item id.
     *
     * Computed for the whole catalogue in two queries rather than per item:
     * a count sheet asks this of every product it lists, and the per-item
     * version of this method is how a stock page becomes a hundred queries.
     *
     * @param  array<int, string>|null  $itemIds  null for every item the company has
     * @return array<string, float>
     */
    public function unitCosts(Company $company, ?array $itemIds = null): array
    {
        $movements = StockMovement::query()
            ->withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->whereNotNull('unit_cost')
            ->where('quantity', '>', 0)
            ->when($itemIds !== null, fn ($q) => $q->whereIn('item_id', $itemIds))
            ->selectRaw('item_id, SUM(quantity) AS qty, SUM(quantity * unit_cost) AS value')
            ->groupBy('item_id')
            ->get();

        $costs = [];

        foreach ($movements as $row) {
            $quantity = (float) $row->qty;

            if ($quantity > 0) {
                $costs[$row->item_id] = round((float) $row->value / $quantity, 2);
            }
        }

        // Anything the ledger cannot price falls back to the catalogue's own
        // cost — better than nothing, and the only figure a business that has
        // never recorded a delivery has.
        $fallback = Item::query()
            ->withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->when($itemIds !== null, fn ($q) => $q->whereIn('id', $itemIds))
            ->whereNotNull('cost')
            ->pluck('cost', 'id');

        foreach ($fallback as $id => $cost) {
            $costs[$id] ??= round((float) $cost, 2);
        }

        return $costs;
    }

    /** One item's weighted average cost. Prefer unitCosts() when asking about more than one. */
    public function unitCost(Company $company, Item $item): float
    {
        return $this->unitCosts($company, [$item->id])[$item->id] ?? 0.0;
    }

    /**
     * Quantity on hand per item, optionally at one location and as at a date.
     *
     * @return array<string, float>
     */
    public function quantities(Company $company, ?StockLocation $location = null, ?string $asOf = null): array
    {
        return StockMovement::query()
            ->withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->when($location !== null, fn ($q) => $q->where('stock_location_id', $location->id))
            ->when($asOf !== null, fn ($q) => $q->where('occurred_at', '<=', $asOf.' 23:59:59'))
            ->selectRaw('item_id, SUM(quantity) AS qty')
            ->groupBy('item_id')
            ->pluck('qty', 'item_id')
            ->map(fn ($qty) => round((float) $qty, 3))
            ->all();
    }

    /**
     * Every tracked product with what is on hand and what it is worth.
     *
     * Items with nothing on hand are kept: a count sheet has to list the thing
     * the ledger says you have none of, because "none" is exactly the claim a
     * count is there to test.
     *
     * @return Collection<int, array{item: Item, quantity: float, unit_cost: float, value: float}>
     */
    public function holdings(Company $company, ?StockLocation $location = null, ?string $asOf = null): Collection
    {
        $items = Item::query()
            ->withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->whereNull('deleted_at')
            ->where('type', 'product')
            ->where('track_stock', true)
            ->orderBy('name')
            ->get();

        if ($items->isEmpty()) {
            return collect();
        }

        $ids = $items->pluck('id')->all();
        $quantities = $this->quantities($company, $location, $asOf);
        $costs = $this->unitCosts($company, $ids);

        return $items->map(function (Item $item) use ($quantities, $costs) {
            $quantity = $quantities[$item->id] ?? 0.0;
            $cost = $costs[$item->id] ?? 0.0;

            return [
                'item' => $item,
                'quantity' => $quantity,
                'unit_cost' => $cost,
                'value' => round($quantity * $cost, 2),
            ];
        })->values();
    }

    /** What the whole stock is worth, which is the figure account 31 should hold. */
    public function totalValue(Company $company, ?StockLocation $location = null, ?string $asOf = null): float
    {
        return round($this->holdings($company, $location, $asOf)->sum('value'), 2);
    }

    /**
     * Tracked products holding stock that nobody has ever priced.
     *
     * These are the reason a valuation can be quietly too low, so a screen that
     * shows a total owes the reader this list.
     *
     * @return Collection<int, Item>
     */
    public function itemsWithoutCost(Company $company, ?StockLocation $location = null): Collection
    {
        return $this->holdings($company, $location)
            ->filter(fn (array $row) => $row['unit_cost'] <= 0 && abs($row['quantity']) > 0.0005)
            ->map(fn (array $row) => $row['item'])
            ->values();
    }
}
