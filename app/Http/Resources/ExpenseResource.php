<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** The wire shape of a supplier bill or a direct expense. */
class ExpenseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'reference' => $this->reference,
            'supplier_id' => $this->supplier_id,
            'supplier_name' => $this->whenLoaded('supplier', fn () => $this->supplier?->name),
            'description' => $this->description,
            'category' => $this->category,
            'status' => $this->status,
            'issue_date' => $this->issue_date?->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            'currency' => $this->currency,
            'amount' => (float) $this->amount,
            'vat_rate' => (float) $this->vat_rate,
            'vat_amount' => (float) $this->vat_amount,
            'total' => (float) $this->total,
            'amount_paid' => (float) $this->amount_paid,
            'balance' => (float) $this->balance(),
            'payment_method' => $this->payment_method,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
