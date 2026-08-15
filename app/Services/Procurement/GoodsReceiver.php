<?php

namespace App\Services\Procurement;

use App\Enums\DocumentType;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Document;
use App\Models\DocumentLine;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\Item;
use App\Models\StockLocation;
use App\Models\User;
use App\Services\Stock\StockLedger;
use App\Support\CurrentCompany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Booking in a delivery.
 *
 * The middle leg of the procurement cycle: something was ordered, something
 * arrived, something will be billed — and the three are rarely identical. This
 * records the second, moves the stock, and keeps enough of a trail for the
 * third to be checked against the first.
 *
 * Receiving is what moves stock, not ordering. A purchase order is a promise;
 * a shelf holds what turned up. Booking stock in when the order is raised is
 * the single most common way inventory drifts away from reality.
 */
class GoodsReceiver
{
    public function __construct(protected StockLedger $stock) {}

    /**
     * @param  array<int, array{item_id?: string|null, document_line_id?: string|null, description?: string, quantity: float|string, unit_cost?: float|string|null}>  $lines
     */
    public function receive(
        Contact $supplier,
        array $lines,
        User $actor,
        ?Document $order = null,
        ?StockLocation $location = null,
        ?string $receivedOn = null,
        ?string $deliveryNoteRef = null,
        ?string $notes = null,
    ): GoodsReceipt {
        $company = app(CurrentCompany::class)->get();

        if ($company === null) {
            throw new RuntimeException('Cannot receive goods without a current company.');
        }

        if ($order !== null && $order->type !== DocumentType::PurchaseOrder) {
            throw new RuntimeException('Goods can only be received against a purchase order.');
        }

        $clean = $this->normalise($lines);

        if ($clean === []) {
            throw new RuntimeException('A delivery needs at least one line.');
        }

        return DB::transaction(function () use ($company, $supplier, $clean, $actor, $order, $location, $receivedOn, $deliveryNoteRef, $notes) {
            $receipt = GoodsReceipt::create([
                'purchase_order_id' => $order?->id,
                'supplier_id' => $supplier->id,
                'stock_location_id' => $location?->id,
                'delivery_note_ref' => $deliveryNoteRef,
                'received_on' => $receivedOn ?? now()->toDateString(),
                'notes' => $notes,
                'received_by' => $actor->id,
            ]);

            foreach ($clean as $index => $line) {
                GoodsReceiptLine::create([
                    'goods_receipt_id' => $receipt->id,
                    'item_id' => $line['item_id'],
                    'document_line_id' => $line['document_line_id'],
                    'description' => $line['description'],
                    'quantity' => $line['quantity'],
                    'unit_cost' => $line['unit_cost'],
                    'sort_order' => $index,
                ]);

                /*
                 * Only tracked items move stock. A delivery routinely mixes
                 * things the business keeps on a shelf with things it does not
                 * — a service call-out, a delivery charge — and those belong on
                 * the receipt for the bill to be matched against without
                 * inventing a stock record for them.
                 */
                if ($line['item_id'] === null) {
                    continue;
                }

                $item = Item::find($line['item_id']);

                if ($item === null || ! $this->tracksStock($item)) {
                    continue;
                }

                $this->stock->receive(
                    company: $company,
                    item: $item,
                    quantity: $line['quantity'],
                    unitCost: $line['unit_cost'],
                    location: $location,
                    actor: $actor,
                    reason: 'purchase',
                    occurredAt: $receipt->received_on->toDateString(),
                );
            }

            return $receipt->fresh('lines');
        });
    }

    /**
     * How much of each order line is still outstanding.
     *
     * Keyed by document line id. This is what tells somebody booking in a
     * second delivery what is still owed, and what stops a supplier being paid
     * for forty units when thirty arrived.
     *
     * @return Collection<string, array{ordered: float, received: float, outstanding: float, description: string}>
     */
    public function outstandingFor(Document $order): Collection
    {
        $received = GoodsReceiptLine::query()
            ->whereIn('document_line_id', $order->lines->pluck('id'))
            ->get()
            ->groupBy('document_line_id')
            ->map(fn (Collection $lines) => (float) $lines->sum(fn ($l) => (float) $l->quantity));

        return $order->lines->mapWithKeys(function (DocumentLine $line) use ($received) {
            $ordered = (float) $line->quantity;
            $got = (float) ($received[$line->id] ?? 0);

            return [$line->id => [
                'description' => $line->description,
                'ordered' => round($ordered, 3),
                'received' => round($got, 3),
                // Never negative: an over-delivery is a real thing that
                // happens, and reporting "-5 outstanding" would read as though
                // the business still owed stock it had already been sent.
                'outstanding' => round(max(0, $ordered - $got), 3),
            ]];
        });
    }

    /**
     * Where an order has got to.
     *
     * `partial` is deliberately distinct from `pending`: a business chasing a
     * supplier needs to know the difference between nothing having arrived and
     * half of it having arrived, and a single "open" status collapses the two
     * cases that call for different phone calls.
     */
    public function statusOf(Document $order): string
    {
        $lines = $this->outstandingFor($order);

        if ($lines->isEmpty()) {
            return 'pending';
        }

        $outstanding = $lines->sum('outstanding');
        $received = $lines->sum('received');

        return match (true) {
            $received <= 0 => 'pending',
            $outstanding <= 0 => 'complete',
            default => 'partial',
        };
    }

    /**
     * The three-way match: ordered, received, billed.
     *
     * A discrepancy here is the whole reason procurement exists as a cycle
     * rather than as a pile of invoices. Being billed for goods that never
     * arrived is the most expensive routine mistake a small business makes,
     * and it is invisible unless something holds the three numbers together.
     *
     * @return array{ordered: float, received: float, billed: float, matched: bool, variance: float}
     */
    public function threeWayMatch(Document $order): array
    {
        $ordered = round((float) $order->total, 2);

        $received = round(
            GoodsReceipt::query()
                ->where('purchase_order_id', $order->id)
                ->with('lines')
                ->get()
                ->sum(fn (GoodsReceipt $r) => $r->value()),
            2,
        );

        $billed = round(
            (float) \App\Models\Expense::query()
                ->where('purchase_order_id', $order->id)
                ->where('status', '!=', 'void')
                ->sum('total'),
            2,
        );

        // A franc of tolerance: XAF has no minor unit, so sub-franc drift
        // between a document total and a sum of line costs is arithmetic, not
        // a discrepancy worth putting in front of anybody.
        $variance = round($billed - $received, 2);

        return [
            'ordered' => $ordered,
            'received' => $received,
            'billed' => $billed,
            'variance' => $variance,
            'matched' => abs($variance) < 1.0,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array<string, mixed>>
     */
    protected function normalise(array $lines): array
    {
        return collect($lines)
            ->map(fn ($line) => [
                'item_id' => $line['item_id'] ?? null,
                'document_line_id' => $line['document_line_id'] ?? null,
                'description' => trim((string) ($line['description'] ?? '')) ?: 'Item',
                'quantity' => round((float) ($line['quantity'] ?? 0), 3),
                'unit_cost' => isset($line['unit_cost']) && $line['unit_cost'] !== ''
                    ? round((float) $line['unit_cost'], 2)
                    : null,
            ])
            // A zero-quantity line is somebody's blank row, not a delivery of
            // nothing. Negative quantities are a return, which is its own act.
            ->reject(fn ($line) => $line['quantity'] <= 0)
            ->values()
            ->all();
    }

    protected function tracksStock(Item $item): bool
    {
        return (bool) ($item->track_stock ?? true);
    }
}
