<?php

namespace App\Services\Stock;

use App\Models\Company;
use App\Models\Item;
use App\Models\StockBatch;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\StockReservation;
use App\Models\User;
use App\Support\CurrentCompany;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Stock that is spoken for but has not left the shelf.
 *
 * A quote, a picking list, a customer order or a basket can all promise stock
 * before anybody carries it out of the door. Promising is deliberately not a
 * movement: stock on hand must keep agreeing with what a person counts on the
 * shelf, so only *available* drops.
 *
 * Available = on hand − live reservations. Nothing else in the product is
 * required to consult it, which is what makes this opt-in: a business that
 * never reserves anything sees available equal on-hand forever.
 */
class StockReservations
{
    /**
     * Promise some stock.
     *
     * Refuses to promise the same unit twice — the failure mode is two
     * customers each told their order is ready and one of them finding out at
     * the counter.
     */
    public function reserve(
        Item $item,
        float $quantity,
        ?StockLocation $location = null,
        ?StockBatch $batch = null,
        ?Model $for = null,
        ?DateTimeInterface $expiresAt = null,
        ?User $actor = null,
        ?string $note = null,
    ): StockReservation {
        $quantity = round($quantity, 3);

        if ($quantity <= 0) {
            throw new RuntimeException('A reservation has to be for some stock.');
        }

        $company = $this->company();

        return DB::transaction(function () use ($company, $item, $quantity, $location, $batch, $for, $expiresAt, $actor, $note) {
            /*
             * The item row is the mutex serialising this check-then-act.
             * "Available" is SUM(movements) − SUM(live reservations): pure
             * aggregates, no row of their own to lock, so two concurrent
             * reserves would read the same snapshot, both pass the check
             * below, and both insert — the same unit promised to two
             * customers. Taking the item row FOR UPDATE first makes the
             * second transaction wait and re-run the sums against the
             * winner's committed reservation. (A no-op on sqlite, where the
             * single writer serialises anyway.)
             */
            if ($item->track_stock) {
                Item::query()->withoutGlobalScopes()->whereKey($item->getKey())->lockForUpdate()->first();
            }

            $available = $batch
                ? $this->availableOfBatch($batch, $location)
                : $this->availableOf($item, $location);

            /*
             * Only checked where "available" means something. A service, or a
             * product nobody counts, has no figure to compare against and
             * refusing on one would be refusing on a number that is always 0.
             */
            if ($item->track_stock && $quantity - $available > 0.0005) {
                $where = $location ? " at {$location->name}" : '';

                throw new RuntimeException("Only {$available} of {$item->name} is available{$where}.");
            }

            return StockReservation::create([
                'company_id' => $company->id,
                'item_id' => $item->id,
                'stock_location_id' => $location?->id ?? $batch?->movements()->value('stock_location_id'),
                'stock_batch_id' => $batch?->id,
                'quantity' => $quantity,
                // Set explicitly: a DB default does not reach the model that
                // the caller is about to read status off.
                'status' => StockReservation::STATUS_ACTIVE,
                'expires_at' => $expiresAt,
                'reference_type' => $for ? $for::class : null,
                'reference_id' => $for?->getKey(),
                'note' => $note,
                'created_by' => $actor?->id,
            ]);
        });
    }

    /** Give the stock back — the order was cancelled, the quote lapsed. */
    public function release(StockReservation $reservation): StockReservation
    {
        $reservation->update(['status' => StockReservation::STATUS_RELEASED]);

        return $reservation;
    }

    /**
     * The goods have gone. Stops the promise holding stock without touching
     * the ledger — the movement that actually took them off the shelf is
     * written by whatever issued them, and writing one here too would take
     * them off twice.
     */
    public function fulfil(StockReservation $reservation): StockReservation
    {
        $reservation->update(['status' => StockReservation::STATUS_FULFILLED]);

        return $reservation;
    }

    /** Release every promise made for one record — a cancelled order, say. */
    public function releaseFor(Model $reference): int
    {
        return StockReservation::query()
            ->where('reference_type', $reference::class)
            ->where('reference_id', $reference->getKey())
            ->holding()
            ->update(['status' => StockReservation::STATUS_RELEASED]);
    }

    public function reservedOf(Item $item, ?StockLocation $location = null): float
    {
        $query = StockReservation::query()->where('item_id', $item->id)->holding();

        if ($location) {
            $query->where('stock_location_id', $location->id);
        }

        return round((float) $query->sum('quantity'), 3);
    }

    /** On the shelf and not promised to anybody. */
    public function availableOf(Item $item, ?StockLocation $location = null): float
    {
        return round($this->onHand($item, $location) - $this->reservedOf($item, $location), 3);
    }

    public function reservedOfBatch(StockBatch $batch, ?StockLocation $location = null): float
    {
        $query = StockReservation::query()->where('stock_batch_id', $batch->id)->holding();

        if ($location) {
            $query->where('stock_location_id', $location->id);
        }

        return round((float) $query->sum('quantity'), 3);
    }

    public function availableOfBatch(StockBatch $batch, ?StockLocation $location = null): float
    {
        $query = StockMovement::query()->where('stock_batch_id', $batch->id);

        if ($location) {
            $query->where('stock_location_id', $location->id);
        }

        return round((float) $query->sum('quantity') - $this->reservedOfBatch($batch, $location), 3);
    }

    /**
     * Reservations that have lapsed, tidied into 'released'.
     *
     * Purely cosmetic — `holding()` already ignores them, so stock is never
     * held by a lapsed promise even if this never runs.
     */
    public function sweepExpired(): int
    {
        return StockReservation::query()
            ->where('status', StockReservation::STATUS_ACTIVE)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->update(['status' => StockReservation::STATUS_RELEASED]);
    }

    protected function onHand(Item $item, ?StockLocation $location = null): float
    {
        $query = StockMovement::query()->where('item_id', $item->id);

        if ($location) {
            $query->where('stock_location_id', $location->id);
        }

        return round((float) $query->sum('quantity'), 3);
    }

    protected function company(): Company
    {
        return app(CurrentCompany::class)->get()
            ?? throw new RuntimeException('Cannot reserve stock without a current company.');
    }
}
