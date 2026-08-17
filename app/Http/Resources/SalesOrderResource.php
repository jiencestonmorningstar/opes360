<?php

namespace App\Http\Resources;

use App\Models\SalesOrderLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The wire shape of a sales order.
 *
 * Quantities per line are the whole story of fulfilment — ordered, reserved,
 * backordered, delivered, invoiced — so the lines carry all five. Totals are
 * emitted only when the lines are loaded, because they are sums over them.
 */
class SalesOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'status' => $this->status,
            'contact_id' => $this->contact_id,
            'currency' => $this->currency,
            'promised_date' => $this->promised_date?->toDateString(),
            'stock_location_id' => $this->stock_location_id,
            'source_document_id' => $this->source_document_id,
            'notes' => $this->notes,
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'credit_override_reason' => $this->credit_override_reason,
            'total' => $this->whenLoaded('lines', fn () => $this->total()),
            'backordered_total' => $this->whenLoaded('lines', fn () => $this->backorderedTotal()),
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn (SalesOrderLine $line) => [
                'id' => $line->id,
                'item_id' => $line->item_id,
                'description' => $line->description,
                'unit' => $line->unit,
                'quantity_ordered' => (float) $line->quantity_ordered,
                'quantity_reserved' => (float) $line->quantity_reserved,
                'quantity_backordered' => (float) $line->quantity_backordered,
                'quantity_delivered' => (float) $line->quantity_delivered,
                'quantity_invoiced' => (float) $line->quantity_invoiced,
                'unit_price' => (float) $line->unit_price,
            ])->all()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
