<?php

namespace App\Services\Orders;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Company;
use App\Models\DeliveryNote;
use App\Models\DeliveryNoteLine;
use App\Models\Document;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\DocumentConverter;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Goods coming back — the undo that cancel() refuses to be.
 *
 * A return is recorded against the delivery note the goods left on, which is
 * what makes "no more than was delivered" a checkable fact: each note line
 * carries a running quantity_returned, and the ceiling is delivered minus
 * that.
 *
 * The stock comes back through the EXISTING ledger — ordinary StockMovements,
 * reason 'return', positive quantity, referenced to the delivery note, at the
 * note's own location. unit_cost stays null for the StockLedger reason: cost
 * is a question about the stock, not the sale.
 *
 * The money follows the goods through the EXISTING credit-note path:
 *
 *  - the part of the return that was never invoiced simply reduces the order
 *    line's quantity_delivered, so the next invoice() cannot bill goods that
 *    came back — no document exists yet, so none is corrected;
 *  - the part that WAS invoiced raises a credit note through the one
 *    DocumentConverter::creditNote() generator, against the order's issued
 *    invoice, at that invoice's own effective tax rate.
 */
class Returns
{
    public function __construct(protected DocumentConverter $converter) {}

    /**
     * Record a return against a delivery note.
     *
     * @param  array<string, float|string|null>  $quantities  delivery note line
     *                                                        id → quantity coming back. Missing/null/0 means "nothing on
     *                                                        that line".
     * @return array{movements: int, credit_note: ?Document}
     */
    public function record(DeliveryNote $note, array $quantities, User $actor, string $reason): array
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new RuntimeException('A return must say why the goods came back.');
        }

        if ($note->status === DeliveryNote::STATUS_VOID) {
            throw new RuntimeException("{$note->number} is void — nothing was ever delivered on it.");
        }

        $company = $this->company();
        $note->loadMissing(['lines.orderLine', 'lines.item', 'order']);

        $returns = [];

        foreach ($note->lines as $line) {
            $quantity = round((float) ($quantities[$line->id] ?? 0), 3);

            if ($quantity <= 0) {
                continue;
            }

            /*
             * The refusal the brief names: more cannot come back than went
             * out. The ceiling is this note line's delivered quantity minus
             * what has already been returned against it.
             */
            $returnable = $line->returnableQuantity();

            if ($quantity - $returnable > 0.0005) {
                throw new RuntimeException(
                    "Only {$returnable} of {$line->description} from {$note->number} can still come back — {$quantity} was not delivered on it."
                );
            }

            $returns[] = [$line, $quantity];
        }

        if ($returns === []) {
            throw new RuntimeException('A return has to bring something back.');
        }

        return DB::transaction(function () use ($note, $returns, $actor, $reason, $company) {
            $movements = 0;
            $creditableValue = 0.0;

            foreach ($returns as [$line, $quantity]) {
                /** @var DeliveryNoteLine $line */
                $orderLine = $line->orderLine;

                // Back onto the shelf through the ordinary ledger, at the
                // shelf the note says the goods left from.
                if ($line->item !== null && $line->item->type === 'product' && $line->item->track_stock) {
                    StockMovement::create([
                        'company_id' => $company->id,
                        'item_id' => $line->item_id,
                        'stock_location_id' => $note->stock_location_id,
                        'quantity' => $quantity,
                        'unit_cost' => null,
                        'reason' => 'return',
                        'reference_type' => DeliveryNote::class,
                        'reference_id' => $note->id,
                        'user_id' => $actor->id,
                        'occurred_at' => now(),
                    ]);

                    $movements++;
                }

                $line->forceFill([
                    'quantity_returned' => round((float) $line->quantity_returned + $quantity, 3),
                ])->save();

                if ($orderLine === null) {
                    continue;
                }

                /*
                 * The uninvoiced part of the return never reaches a credit
                 * note — it comes off quantity_delivered so the next
                 * invoice() cannot bill it. Only what was already invoiced
                 * has a document to correct, and its value goes to the one
                 * credit-note generator below.
                 */
                $uninvoiced = max(0.0, $orderLine->uninvoicedQuantity());
                $offUninvoiced = round(min($quantity, $uninvoiced), 3);
                $offInvoiced = round($quantity - $offUninvoiced, 3);

                $orderLine->forceFill([
                    'quantity_delivered' => round((float) $orderLine->quantity_delivered - $quantity, 3),
                    'quantity_invoiced' => round((float) $orderLine->quantity_invoiced - $offInvoiced, 3),
                ])->save();

                $creditableValue += $offInvoiced * (float) $orderLine->unit_price;
            }

            $creditNote = $creditableValue > 0.005
                ? $this->credit($note, round($creditableValue, 2), $actor, $reason)
                : null;

            $note->order?->emitDomainEvent('order.return.recorded', [
                'delivery_note' => $note->number,
                'reason' => $reason,
                'credit_note' => $creditNote?->number,
            ]);

            return ['movements' => $movements, 'credit_note' => $creditNote];
        });
    }

    /**
     * Credit the invoiced value of the return through the one credit-note
     * generator, against the order's issued invoice with room left to credit.
     *
     * The value handed in is net (quantity × order-line price, the figure the
     * invoice billed); it is grossed up by the invoice's own effective rate so
     * a 19.25% invoice produces a 19.25% credit note and a zero-rated one
     * credits exactly the net — the same stance as DebitNotes::split().
     */
    protected function credit(DeliveryNote $note, float $netValue, User $actor, string $reason): Document
    {
        $invoice = $note->order?->invoices()
            ->where('documents.status', '!=', DocumentStatus::Void)
            ->where('documents.type', DocumentType::Invoice)
            ->whereNotNull('documents.number')
            ->orderByDesc('documents.created_at')
            ->get()
            ->first(fn (Document $candidate) => $this->converter->creditableAmount($candidate) > 0.005);

        if ($invoice === null) {
            throw new RuntimeException(
                "The returned goods were invoiced, but no issued invoice on {$note->order?->number} has room left to credit."
            );
        }

        $subtotal = (float) $invoice->subtotal;
        $gross = $subtotal > 0
            ? round($netValue * ((float) $invoice->total / $subtotal), 2)
            : $netValue;

        // Never ask for more than is left — a fully part-credited invoice
        // caps the note at the remainder rather than failing the return.
        $gross = min($gross, $this->converter->creditableAmount($invoice));

        return $this->converter->creditNote($invoice, $actor, $gross, "Retour {$note->number} — {$reason}");
    }

    protected function company(): Company
    {
        return app(CurrentCompany::class)->get()
            ?? throw new RuntimeException('Cannot record a return without a current company.');
    }
}
