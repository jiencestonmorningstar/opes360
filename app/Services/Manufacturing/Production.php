<?php

namespace App\Services\Manufacturing;

use App\Models\BillOfMaterial;
use App\Models\Company;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderLine;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Stock\BatchLedger;
use App\Services\Stock\StockValuation;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Running a recipe: raise an order, and complete it against the shelves.
 *
 * ── What completion is ───────────────────────────────────────────────────
 *
 * One transaction that writes ordinary stock movements: each component out,
 * the finished goods in. Nothing here keeps its own quantities — stock on
 * hand, the count sheet and the valuation all read the same append-only
 * ledger they always did, and a completed order shows up in all three
 * without any of them knowing manufacturing exists.
 *
 * ── What the finished goods cost ─────────────────────────────────────────
 *
 * The sum of what the components were worth at the ledger's own weighted
 * average, divided by the units made. That figure rides in on the receipt
 * movement's `unit_cost` exactly as a supplier delivery's price would, so
 * StockValuation prices the finished product with the machinery it already
 * has. There is no second costing engine to disagree with the first.
 *
 * ── Refusing short ───────────────────────────────────────────────────────
 *
 * A completion that cannot be covered is refused whole, naming the component
 * that is short. Driving a component negative would manufacture stock out of
 * planks the ledger says were never there, and the valuation built on that
 * ledger would quietly inherit the fiction.
 */
class Production
{
    public function __construct(
        protected StockValuation $valuation,
        protected BatchLedger $batches,
    ) {}

    /**
     * Raise an order: make $quantity of what this recipe makes.
     *
     * The recipe is snapshotted onto the order's lines, scrap included, so a
     * recipe edited tomorrow cannot restate what this order was going to use.
     */
    public function create(
        Company $company,
        BillOfMaterial $bom,
        float $quantity,
        ?StockLocation $location = null,
        ?User $actor = null,
        ?string $note = null,
    ): ProductionOrder {
        $quantity = round($quantity, 3);

        if ($quantity <= 0) {
            throw new RuntimeException('An order has to make at least something.');
        }

        $bom->loadMissing('lines.item', 'item');

        if ($bom->lines->isEmpty()) {
            throw new RuntimeException('This recipe has no components yet, so there is nothing to make it from.');
        }

        return DB::transaction(function () use ($company, $bom, $quantity, $location, $actor, $note) {
            $order = ProductionOrder::create([
                'company_id' => $company->id,
                'bill_of_material_id' => $bom->id,
                'item_id' => $bom->item_id,
                'reference' => $this->nextReference($company),
                'quantity' => $quantity,
                'status' => ProductionOrder::STATUS_PLANNED,
                'stock_location_id' => $location?->id,
                'note' => $note,
                'created_by' => $actor?->id,
            ]);

            foreach ($bom->demandFor($quantity) as $itemId => $required) {
                ProductionOrderLine::create([
                    'production_order_id' => $order->id,
                    'item_id' => $itemId,
                    'quantity_required' => $required,
                ]);
            }

            return $order->refresh();
        });
    }

    /** Work has begun. Purely informational — nothing moves until completion. */
    public function start(ProductionOrder $order, ?User $actor = null): ProductionOrder
    {
        if ($order->status !== ProductionOrder::STATUS_PLANNED) {
            throw new RuntimeException('Only a planned order can be started.');
        }

        $order->forceFill([
            'status' => ProductionOrder::STATUS_IN_PROGRESS,
            'started_at' => now(),
        ])->save();

        return $order->refresh();
    }

    /**
     * The whole point: consume the components, receive the finished goods.
     *
     * @param  string|null  $lotCode  the lot the finished goods become, when the finished product is lot-tracked
     *
     * @throws RuntimeException when the order is not open, a component is short, or a traced finished good has no lot
     */
    public function complete(ProductionOrder $order, ?User $actor = null, ?string $lotCode = null): ProductionOrder
    {
        if (! $order->isOpen()) {
            throw new RuntimeException('This order has already been completed or cancelled.');
        }

        $company = Company::query()->findOrFail($order->company_id);

        return DB::transaction(function () use ($order, $company, $actor, $lotCode) {
            $order->loadMissing('lines.item', 'item', 'location');

            $finished = $order->item;

            // Asked before anything moves: a refusal after half the
            // components were consumed is exactly what the transaction and
            // this early check together rule out.
            if ($finished->isTraceable() && trim((string) $lotCode) === '') {
                throw new RuntimeException("{$finished->name} is tracked by lot, so the finished goods need a lot number.");
            }

            $location = $order->location;
            $onHand = $this->valuation->quantities($company, $location);

            foreach ($order->lines as $line) {
                $required = (float) $line->quantity_required;
                $available = $onHand[$line->item_id] ?? 0.0;

                if ($required - $available > 0.0005) {
                    throw new RuntimeException(
                        "Not enough {$line->item->name}: {$required} needed, {$available} on hand."
                    );
                }
            }

            $costs = $this->valuation->unitCosts($company, $order->lines->pluck('item_id')->all());
            $total = 0.0;

            foreach ($order->lines as $line) {
                $required = (float) $line->quantity_required;
                $unitCost = $costs[$line->item_id] ?? 0.0;

                if ($line->item->isTraceable()) {
                    // FEFO across the component's lots — the same picker a
                    // sale uses, so the recall trail stays whole.
                    $this->batches->pick($line->item, $location, $required, 'production', $actor, $order);
                } else {
                    StockMovement::create([
                        'company_id' => $company->id,
                        'item_id' => $line->item_id,
                        'stock_location_id' => $location?->id,
                        'quantity' => round(-$required, 3),
                        // Like every outgoing movement: what it cost is a
                        // question about the stock it came out of, and the
                        // weighted average is computed from arrivals only.
                        'unit_cost' => null,
                        'reason' => 'production',
                        'reference_type' => ProductionOrder::class,
                        'reference_id' => $order->id,
                        'user_id' => $actor?->id,
                        'occurred_at' => now(),
                    ]);
                }

                $line->forceFill([
                    'quantity_consumed' => $required,
                    'unit_cost' => $unitCost,
                ])->save();

                $total += $required * $unitCost;
            }

            $total = round($total, 2);
            $unit = round($total / (float) $order->quantity, 2);

            if ($finished->isTraceable()) {
                $this->batches->receive($finished, $location, (float) $order->quantity, [
                    'code' => trim((string) $lotCode),
                    'unit_cost' => $unit,
                    'manufactured_on' => now()->toDateString(),
                ], $actor, 'production');
            } else {
                StockMovement::create([
                    'company_id' => $company->id,
                    'item_id' => $finished->id,
                    'stock_location_id' => $location?->id,
                    'quantity' => round((float) $order->quantity, 3),
                    // The arrival that carries the cost, exactly as a
                    // delivery would — this is what lets the existing
                    // valuation price the finished product.
                    'unit_cost' => $unit,
                    'reason' => 'production',
                    'reference_type' => ProductionOrder::class,
                    'reference_id' => $order->id,
                    'user_id' => $actor?->id,
                    'occurred_at' => now(),
                ]);
            }

            $order->forceFill([
                'status' => ProductionOrder::STATUS_COMPLETED,
                'completed_at' => now(),
                'total_cost' => $total,
                'unit_cost' => $unit,
                'completed_by' => $actor?->id,
            ])->save();

            return $order->refresh();
        });
    }

    /** An order abandoned before completion. Nothing was consumed, so nothing is put back. */
    public function cancel(ProductionOrder $order, ?User $actor = null): ProductionOrder
    {
        if (! $order->isOpen()) {
            throw new RuntimeException('This order has already been completed or cancelled.');
        }

        $order->forceFill(['status' => ProductionOrder::STATUS_CANCELLED])->save();

        return $order->refresh();
    }

    /**
     * Can we make $units now? One row per component: what the recipe demands,
     * what the shelf holds, and the gap.
     *
     * @return array<int, array{item: \App\Models\Item, required: float, on_hand: float, short: float}>
     */
    public function availability(
        Company $company,
        BillOfMaterial $bom,
        float $units,
        ?StockLocation $location = null,
    ): array {
        $bom->loadMissing('lines.item');

        $onHand = $this->valuation->quantities($company, $location);
        $demand = $bom->demandFor($units);

        $rows = [];

        foreach ($bom->lines as $line) {
            $required = $demand[$line->item_id] ?? 0.0;
            $available = $onHand[$line->item_id] ?? 0.0;

            $rows[] = [
                'item' => $line->item,
                'required' => $required,
                'on_hand' => $available,
                'short' => round(max(0, $required - $available), 3),
            ];
        }

        return $rows;
    }

    /**
     * The most finished units the shelves cover right now — the read model a
     * list can show next to each recipe. Whole units, because half a table is
     * not a table.
     */
    public function makeable(Company $company, BillOfMaterial $bom, ?StockLocation $location = null): float
    {
        $bom->loadMissing('lines');

        if ($bom->lines->isEmpty()) {
            return 0.0;
        }

        $onHand = $this->valuation->quantities($company, $location);
        $perUnit = $bom->demandFor(1);

        $limit = INF;

        foreach ($perUnit as $itemId => $needed) {
            if ($needed <= 0) {
                continue;
            }

            $limit = min($limit, ($onHand[$itemId] ?? 0.0) / $needed);
        }

        return $limit === INF ? 0.0 : floor($limit + 0.0005);
    }

    /**
     * MO-2026-0001, per company per year. Counted and retried on collision,
     * like every other reference in this system — the unique index is the
     * proof two people raising orders at once cannot share one.
     */
    protected function nextReference(Company $company): string
    {
        $prefix = 'MO-'.now()->format('Y').'-';

        $used = ProductionOrder::query()
            ->withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('reference', 'like', $prefix.'%')
            ->count();

        for ($attempt = 1; $attempt <= 50; $attempt++) {
            $candidate = $prefix.str_pad((string) ($used + $attempt), 4, '0', STR_PAD_LEFT);

            $taken = ProductionOrder::query()
                ->withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->where('reference', $candidate)
                ->exists();

            if (! $taken) {
                return $candidate;
            }
        }

        throw new RuntimeException('Could not allocate a reference for this order.');
    }
}
