<?php

namespace App\Support;

use App\Models\Company;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use Illuminate\Support\Collection;

/**
 * The despatch desk's three questions: what is promised, what can ship
 * today, and what is short.
 *
 * A read model in the style of Replenishment — nothing stored, computed from
 * the orders and their lines. "Shippable" is what confirmation actually
 * holds (the reserved quantities), so the figure agrees with the
 * reservations underneath it by construction. "Short" is the backordered
 * remainders, and for each short item the board also answers the question
 * the storekeeper asks next: is replenishment already on the case? That
 * answer is read from App\Support\Replenishment itself — the same rows the
 * replenishment screen shows — never recomputed here.
 */
class FulfilmentBoard
{
    public function __construct(protected Company $company) {}

    /**
     * One row per open order, most overdue promise first.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(): Collection
    {
        $orders = SalesOrder::query()
            ->where('company_id', $this->company->id)
            ->whereIn('status', [SalesOrder::STATUS_CONFIRMED, SalesOrder::STATUS_PICKING])
            ->with(['contact', 'lines.item'])
            ->orderByRaw('promised_date IS NULL, promised_date')
            ->get();

        if ($orders->isEmpty()) {
            return collect();
        }

        $replenishing = $this->replenishingItemIds();

        return $orders->map(function (SalesOrder $order) use ($replenishing) {
            $lines = $order->lines;

            $shortages = $lines
                ->filter(fn (SalesOrderLine $line) => (float) $line->quantity_backordered > 0)
                ->map(fn (SalesOrderLine $line) => [
                    'item' => $line->item,
                    'short' => (float) $line->quantity_backordered,
                    // True when the replenishment screen already lists this
                    // item — the shortage is known there and an order to fix
                    // it is a click away, so the board need not shout twice.
                    'replenishment_covers' => in_array($line->item_id, $replenishing, true),
                ])
                ->values();

            return [
                'order' => $order,
                // Still owed to the customer, backorders included: the
                // promise does not shrink because the shelf did.
                'promised' => round($lines->sum(
                    fn (SalesOrderLine $l) => (float) $l->quantity_ordered - (float) $l->quantity_delivered
                ), 3),
                // Held and ready to go out of the door right now.
                'shippable' => round((float) $lines->sum('quantity_reserved'), 3),
                'short' => round((float) $lines->sum('quantity_backordered'), 3),
                'shortages' => $shortages,
                'overdue' => $order->promised_date !== null
                    && $order->promised_date->lt(now()->startOfDay()),
            ];
        })->values();
    }

    /**
     * Items the replenishment screen is already flagging for reorder —
     * read from the one Replenishment read model, not a second opinion.
     *
     * @return array<int, string>
     */
    protected function replenishingItemIds(): array
    {
        return (new Replenishment($this->company))
            ->rows()
            ->map(fn (array $row) => $row['item']->id)
            ->all();
    }
}
