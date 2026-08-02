<?php

namespace App\Services\Stock;

use App\Models\Company;
use App\Models\Item;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\StockTransferLine;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Stock, per place.
 *
 * Everything here appends to the movement ledger; nothing updates a total.
 * That is not stylistic — it is what lets two devices sell the last unit
 * offline and be reconciled afterwards instead of overwriting each other, and
 * a per-location quantity column would have thrown it away for the sake of a
 * faster query.
 */
class LocationLedger
{
    /**
     * Move stock between two places.
     *
     * Refuses to move more than is there, refuses a transfer to the same place
     * it came from, and writes the two halves in one transaction — a transfer
     * that took stock out and never put it in would be worse than one that
     * never happened, because the difference would show up as a shortage
     * somebody spends an afternoon looking for.
     *
     * @param  array<int, array{item_id: string, quantity: float}>  $lines
     */
    public function transfer(
        StockLocation $from,
        StockLocation $to,
        array $lines,
        ?User $actor = null,
        ?string $movedOn = null,
        ?string $note = null,
        ?string $reference = null,
    ): StockTransfer {
        $company = $this->company();

        if ($from->id === $to->id) {
            throw new RuntimeException('Stock cannot be transferred to where it already is.');
        }

        $lines = array_values(array_filter($lines, fn (array $l) => (float) $l['quantity'] > 0));

        if ($lines === []) {
            throw new RuntimeException('A transfer needs at least one item on it.');
        }

        return DB::transaction(function () use ($company, $from, $to, $lines, $actor, $movedOn, $note, $reference) {
            $movedOn ??= now()->toDateString();

            $transfer = StockTransfer::create([
                'company_id' => $company->id,
                'reference' => $reference,
                'from_location_id' => $from->id,
                'to_location_id' => $to->id,
                'moved_on' => $movedOn,
                'status' => 'completed',
                'note' => $note,
                'created_by' => $actor?->id,
            ]);

            foreach ($lines as $line) {
                $item = Item::query()->findOrFail($line['item_id']);
                $quantity = round((float) $line['quantity'], 3);

                /*
                 * Only checked for items the business actually tracks. A
                 * service, or a product whose stock nobody counts, has no
                 * "available" figure to compare against and blocking on one
                 * would be blocking on a number that means nothing.
                 */
                if ($item->track_stock) {
                    $available = $this->stockAt($from, $item);

                    if ($quantity - $available > 0.0005) {
                        throw new RuntimeException(
                            "There are only {$available} of {$item->name} at {$from->name}."
                        );
                    }
                }

                StockTransferLine::create([
                    'company_id' => $company->id,
                    'stock_transfer_id' => $transfer->id,
                    'item_id' => $item->id,
                    'quantity' => $quantity,
                ]);

                $this->append($company, $item, $from, -$quantity, 'transfer_out', $transfer, $actor, $movedOn);
                $this->append($company, $item, $to, $quantity, 'transfer_in', $transfer, $actor, $movedOn);
            }

            return $transfer->load('lines');
        });
    }

    /**
     * Record stock arriving at, or leaving, a place.
     *
     * The way an opening count or a correction gets attributed to a location.
     */
    public function adjust(
        Item $item,
        StockLocation $location,
        float $quantity,
        string $reason = 'adjustment',
        ?User $actor = null,
    ): StockMovement {
        return $this->append($this->company(), $item, $location, round($quantity, 3), $reason, null, $actor);
    }

    public function stockAt(StockLocation $location, Item $item): float
    {
        return round((float) StockMovement::query()
            ->where('stock_location_id', $location->id)
            ->where('item_id', $item->id)
            ->sum('quantity'), 3);
    }

    /**
     * One item, everywhere it is.
     *
     * The unattributed movements — everything recorded before the business had
     * locations — are reported under a null key rather than folded into a
     * location that did not exist at the time.
     *
     * @return array<string, float> location id (or '' for unattributed) => quantity
     */
    public function spreadOf(Item $item): array
    {
        return StockMovement::query()
            ->where('item_id', $item->id)
            ->selectRaw('stock_location_id, SUM(quantity) AS quantity')
            ->groupBy('stock_location_id')
            ->get()
            ->mapWithKeys(fn ($row) => [(string) $row->stock_location_id => round((float) $row->quantity, 3)])
            ->all();
    }

    /**
     * Every item held at a location, with its quantity.
     *
     * @return Collection<int, array{item: Item, quantity: float}>
     */
    public function contentsOf(StockLocation $location): Collection
    {
        $quantities = StockMovement::query()
            ->where('stock_location_id', $location->id)
            ->selectRaw('item_id, SUM(quantity) AS quantity')
            ->groupBy('item_id')
            ->pluck('quantity', 'item_id');

        if ($quantities->isEmpty()) {
            return collect();
        }

        return Item::query()
            ->whereIn('id', $quantities->keys())
            ->orderBy('name')
            ->get()
            ->map(fn (Item $item) => ['item' => $item, 'quantity' => round((float) $quantities[$item->id], 3)])
            ->filter(fn (array $row) => abs($row['quantity']) > 0.0005)
            ->values();
    }

    /** The one a business gets when it has not said otherwise. */
    public function defaultLocation(): ?StockLocation
    {
        return StockLocation::query()
            ->where('active', true)
            ->orderByDesc('is_default')
            ->orderBy('created_at')
            ->first();
    }

    protected function append(
        Company $company,
        Item $item,
        StockLocation $location,
        float $quantity,
        string $reason,
        ?StockTransfer $transfer,
        ?User $actor,
        ?string $occurredAt = null,
    ): StockMovement {
        return StockMovement::create([
            'company_id' => $company->id,
            'item_id' => $item->id,
            'stock_location_id' => $location->id,
            'quantity' => $quantity,
            'reason' => $reason,
            'reference_type' => $transfer ? StockTransfer::class : null,
            'reference_id' => $transfer?->id,
            'user_id' => $actor?->id,
            'occurred_at' => $occurredAt ? $occurredAt.' '.now()->format('H:i:s') : now(),
        ]);
    }

    protected function company(): Company
    {
        return app(CurrentCompany::class)->get()
            ?? throw new RuntimeException('Cannot move stock without a current company.');
    }
}
