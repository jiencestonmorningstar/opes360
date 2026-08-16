<?php

namespace App\Support;

use App\Models\Company;
use App\Models\Item;
use App\Models\ItemSupplier;
use App\Models\StockMovement;
use App\Models\StockReservation;
use Illuminate\Support\Collection;

/**
 * What needs ordering, how badly, and from whom.
 *
 * The reorder level alone answers "are we low?". This answers the question a
 * storekeeper actually has: given how fast it is selling and how long the
 * supplier takes, will the shelf go empty before a replacement can arrive?
 * That flag — not the shortage itself — is what changes what somebody does
 * today, so it is computed here rather than left as arithmetic on the screen.
 *
 * A read model in the style of PaymentSchedule: nothing is stored. The thing
 * worth persisting is the decision a human makes from it, and that is an
 * ordinary draft PurchaseRequisition raised by
 * App\Services\Procurement\Replenisher through the existing procurement door.
 *
 * ── The figures, per item ──────────────────────────────────────────────────
 *
 *  available     on hand minus live reservations. Promised stock cannot be
 *                sold twice, so it cannot be counted as cover either.
 *  daily_usage   what left the shelf through sales over the recent window,
 *                per day. Sales reasons only ('sale', 'credit',
 *                'document-void'): transfers and count adjustments move or
 *                correct stock, they do not consume it, and counting them
 *                would tell the business its shelves eat inventory.
 *  days_of_cover available ÷ daily usage; null when nothing is selling.
 *  suggested     up to the maximum level (or the reorder level when no
 *                maximum is set) from what is available, plus what will be
 *                consumed during the supplier's lead time — an order that
 *                only fills today's gap arrives already short.
 */
class Replenishment
{
    public function __construct(
        protected Company $company,
        protected int $usageWindowDays = 30,
    ) {}

    /**
     * Every item that needs ordering: below its reorder level now, or
     * projected to fall below it before an order placed today could arrive.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(): Collection
    {
        $items = Item::query()
            ->where('company_id', $this->company->id)
            ->products()
            ->active()
            ->where('track_stock', true)
            ->whereNotNull('reorder_level')
            ->withStock()
            ->with('supplierLinks.supplier')
            ->orderBy('name')
            ->get();

        if ($items->isEmpty()) {
            return collect();
        }

        $reserved = $this->reservedByItem($items->modelKeys());
        $usage = $this->usageByItem($items->modelKeys());

        return $items
            ->map(function (Item $item) use ($reserved, $usage) {
                $onHand = $item->stockOnHand();
                $available = round($onHand - ($reserved[$item->id] ?? 0.0), 3);
                $dailyUsage = round(($usage[$item->id] ?? 0.0) / $this->usageWindowDays, 3);

                $link = $item->preferredSupplierLink();
                $leadDays = $link?->lead_days;

                $reorder = (float) $item->reorder_level;
                $belowNow = $available <= $reorder;

                /*
                 * Projected forward over the lead time: stock that is fine
                 * today but will cross the line before a delivery could land
                 * needs ordering today, not on the day the alarm rings.
                 * Without a supplier there is no horizon to project over, so
                 * only the here-and-now test applies.
                 */
                $projected = $leadDays !== null
                    ? round($available - $dailyUsage * $leadDays, 3)
                    : $available;

                if (! $belowNow && $projected > $reorder) {
                    return null;
                }

                $daysOfCover = $dailyUsage > 0 ? round($available / $dailyUsage, 1) : null;

                $target = $item->max_level !== null ? (float) $item->max_level : $reorder;

                return [
                    'item' => $item,
                    'on_hand' => round($onHand, 3),
                    'reserved' => round($reserved[$item->id] ?? 0.0, 3),
                    'available' => $available,
                    'reorder_level' => $reorder,
                    'target_level' => $target,
                    'daily_usage' => $dailyUsage,
                    'days_of_cover' => $daysOfCover,
                    'supplier' => $link?->supplier,
                    'lead_days' => $leadDays,
                    'estimated_unit_price' => $link?->last_price !== null
                        ? (float) $link->last_price
                        : ($item->cost !== null ? (float) $item->cost : null),
                    'suggested_quantity' => $this->suggested($target, $available, $dailyUsage, $leadDays),
                    'below_now' => $belowNow,
                    // The killer figure: the shelf goes empty before the
                    // replacement can arrive. Everything else on this screen
                    // is routine; these rows are today's problem.
                    'will_run_out' => $daysOfCover !== null
                        && $leadDays !== null
                        && $daysOfCover < $leadDays,
                ];
            })
            ->filter()
            ->sortBy([
                ['will_run_out', 'desc'],
                ['days_of_cover', 'asc'],
            ])
            ->values();
    }

    /**
     * Enough to be back at the target when the order arrives, not when it is
     * placed: the gap up to the target, plus the lead time's consumption.
     */
    protected function suggested(float $target, float $available, float $dailyUsage, ?int $leadDays): float
    {
        $quantity = $target - $available + $dailyUsage * ($leadDays ?? 0);

        return round(max(0.0, $quantity), 3);
    }

    /**
     * Live reservations, one grouped sum for the whole page.
     *
     * @param  array<int, string>  $itemIds
     * @return array<string, float>
     */
    protected function reservedByItem(array $itemIds): array
    {
        return StockReservation::query()
            ->whereIn('item_id', $itemIds)
            ->holding()
            ->groupBy('item_id')
            ->selectRaw('item_id, SUM(quantity) AS total')
            ->pluck('total', 'item_id')
            ->map(fn ($total) => (float) $total)
            ->all();
    }

    /**
     * Units sold per item over the window, one grouped sum.
     *
     * The reasons are the sales ledger's own: 'sale' takes stock off,
     * 'credit' puts a return back, 'document-void' unwinds a voided invoice.
     * Summed together the signs cancel exactly where they should — a voided
     * sale was not consumption. The window is bound on startOfDay so a
     * movement dated this morning is inside it regardless of the hour.
     *
     * @param  array<int, string>  $itemIds
     * @return array<string, float>  positive units consumed
     */
    protected function usageByItem(array $itemIds): array
    {
        return StockMovement::query()
            ->whereIn('item_id', $itemIds)
            ->whereIn('reason', ['sale', 'credit', 'document-void'])
            ->where('occurred_at', '>=', now()->subDays($this->usageWindowDays)->startOfDay())
            ->groupBy('item_id')
            ->selectRaw('item_id, SUM(quantity) AS total')
            ->pluck('total', 'item_id')
            ->map(fn ($total) => round(max(0.0, -1 * (float) $total), 3))
            ->all();
    }
}
