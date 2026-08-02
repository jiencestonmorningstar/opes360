<?php

namespace App\Services\Stock;

use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentLine;
use App\Models\Item;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Selling something takes it off the shelf.
 *
 * This is the half of stock control that was missing: the ledger was fed by
 * the opening balance, by hand adjustments and by transfers between locations,
 * but not by the thing a shop does forty times a day. Stock on hand was
 * therefore right only for a business that never invoiced, which is no
 * business at all.
 *
 * The movements are written when a document is *issued*, for the same reason
 * the sale reaches the books then: a draft can still change, and nothing has
 * left the shop until the customer has been given a paper that says it has.
 * Voiding writes the opposite movements rather than deleting the originals —
 * the ledger is append-only, and "we sold it and then we didn't" is a thing
 * that happened.
 */
class StockLedger
{
    public function __construct(protected LocationLedger $locations) {}

    /**
     * Move a document's goods.
     *
     * An invoice takes them off the shelf; a credit note puts them back, which
     * is what a credit note usually means — the customer returned something.
     * It is not always what it means: a note issued purely to correct an
     * overcharge restocks goods that never left. Erring towards restocking is
     * the recoverable mistake of the two, because the next inventory finds it,
     * whereas stock silently missing from the shelf is discovered by running
     * out of something the system said was there.
     *
     * Only tracked products move. A service line has nothing to take away, and
     * a line typed freehand does not say what it sold — the composer allows
     * both, and guessing which catalogue item a line of text meant would be
     * worse than leaving the ledger alone.
     *
     * @return int how many movements were written
     */
    public function recordSale(Document $document, Company $company, ?User $actor = null): int
    {
        $sign = $document->type->customerAccountSign();

        if ($sign === 0) {
            return 0;
        }

        return $this->move($document, $company, $actor, -$sign, $sign > 0 ? 'sale' : 'credit');
    }

    /**
     * Undo whatever a document did to the shelf.
     *
     * Computed from the movements the document actually wrote rather than from
     * its lines a second time: the lines can no longer be trusted to produce
     * the same answer — an item may have had stock tracking switched off since
     * — and the movements are the record of what really happened.
     *
     * Guarded against running twice. A second void would restock the shelf
     * again, and the reversing movements are how it knows not to.
     */
    public function reverseSale(Document $document, Company $company, ?User $actor = null): int
    {
        $written = StockMovement::query()
            ->withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('reference_type', Document::class)
            ->where('reference_id', $document->id)
            ->get();

        if ($written->contains(fn (StockMovement $m) => $m->reason === 'document-void')) {
            return 0;
        }

        $original = $written->filter(fn (StockMovement $m) => in_array($m->reason, ['sale', 'credit'], true));

        if ($original->isEmpty()) {
            return 0;
        }

        return DB::transaction(function () use ($original, $document, $company, $actor) {
            foreach ($original as $movement) {
                StockMovement::create([
                    'company_id' => $company->id,
                    'item_id' => $movement->item_id,
                    'stock_location_id' => $movement->stock_location_id,
                    'quantity' => round(-1 * (float) $movement->quantity, 3),
                    'unit_cost' => null,
                    'reason' => 'document-void',
                    'reference_type' => Document::class,
                    'reference_id' => $document->id,
                    'user_id' => $actor?->id,
                    'occurred_at' => now(),
                ]);
            }

            return $original->count();
        });
    }

    /**
     * @param  int  $sign  -1 off the shelf, +1 back onto it
     */
    protected function move(Document $document, Company $company, ?User $actor, int $sign, string $reason): int
    {
        $document->loadMissing('lines.item');

        $sellable = $document->lines->filter(
            fn (DocumentLine $line) => $line->item !== null
                && $line->item->type === 'product'
                && $line->item->track_stock
                && abs((float) $line->quantity) > 0.0005
        );

        if ($sellable->isEmpty()) {
            return 0;
        }

        // One location or none: a business with locations turned off has no
        // shelf to name, and one that has them keeps its stock where it says
        // it does by default. Selling from a specific van is a question the
        // invoice does not currently ask.
        $location = $this->defaultLocationFor($company);

        return DB::transaction(function () use ($sellable, $document, $company, $actor, $sign, $reason, $location) {
            $written = 0;

            foreach ($sellable as $line) {
                StockMovement::create([
                    'company_id' => $company->id,
                    'item_id' => $line->item_id,
                    'stock_location_id' => $location?->id,
                    'quantity' => round($sign * (float) $line->quantity, 3),
                    // What it cost is a question about the stock it came out
                    // of, not about the sale. Left null so the weighted
                    // average is computed from deliveries only.
                    'unit_cost' => null,
                    'reason' => $reason,
                    'reference_type' => Document::class,
                    'reference_id' => $document->id,
                    'user_id' => $actor?->id,
                    'occurred_at' => $document->issue_date?->toDateString().' '.now()->format('H:i:s'),
                ]);

                $written++;
            }

            return $written;
        });
    }

    /**
     * Record stock arriving, with what it cost.
     *
     * The cost is the point: it is the only thing that lets the weighted
     * average mean anything, and a receipt without one is a quantity change
     * that leaves the valuation guessing.
     */
    public function receive(
        Company $company,
        Item $item,
        float $quantity,
        ?float $unitCost = null,
        ?StockLocation $location = null,
        ?User $actor = null,
        string $reason = 'purchase',
        ?string $occurredAt = null,
    ): StockMovement {
        return StockMovement::create([
            'company_id' => $company->id,
            'item_id' => $item->id,
            'stock_location_id' => $location?->id ?? $this->defaultLocationFor($company)?->id,
            'quantity' => round($quantity, 3),
            'unit_cost' => $unitCost !== null ? round($unitCost, 2) : null,
            'reason' => $reason,
            'user_id' => $actor?->id,
            'occurred_at' => $occurredAt ? $occurredAt.' '.now()->format('H:i:s') : now(),
        ]);
    }

    /**
     * The shelf a movement lands on when nobody said.
     *
     * Null is a perfectly good answer — it is what every movement recorded
     * before locations existed carries, and a business that keeps its stock in
     * one room has no more to say than that.
     */
    protected function defaultLocationFor(Company $company): ?StockLocation
    {
        return StockLocation::query()
            ->withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('active', true)
            ->orderByDesc('is_default')
            ->orderBy('created_at')
            ->first();
    }
}
