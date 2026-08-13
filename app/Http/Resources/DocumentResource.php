<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The wire shape of a sales document.
 *
 * Lines are only included when they have been eager-loaded, so a list page
 * does not silently fire a query per row.
 */
class DocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'number' => $this->number,
            'status' => $this->status->value,
            'contact_id' => $this->contact_id,
            'contact_name' => $this->whenLoaded('contact', fn () => $this->contact?->name),
            'issue_date' => $this->issue_date?->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            'currency' => $this->currency,
            'subtotal' => (float) $this->subtotal,
            'discount_total' => (float) $this->discount_total,
            'tax_total' => (float) $this->tax_total,
            'total' => (float) $this->total,
            'amount_paid' => (float) $this->amount_paid,
            'balance' => (float) $this->balance,
            'notes' => $this->notes,
            'terms' => $this->terms,
            'reference' => $this->reference,
            'issued_at' => $this->issued_at?->toIso8601String(),
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($line) => [
                'id' => $line->id,
                'item_id' => $line->item_id,
                'description' => $line->description,
                'quantity' => (float) $line->quantity,
                'unit' => $line->unit,
                'unit_price' => (float) $line->unit_price,
                'tax_amount' => (float) $line->tax_amount,
                'line_total' => (float) $line->line_total,
            ])->all()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
