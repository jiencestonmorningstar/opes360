<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The wire shape of a payment, with its receipt when one has been loaded.
 *
 * The receipt's verification token is included because that is the whole
 * point of it — a caller printing its own copy needs the same QR the app
 * would print, or the printed page cannot be checked.
 */
class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'contact_id' => $this->contact_id,
            'method' => $this->method?->value,
            'amount' => (float) $this->amount,
            'currency' => $this->currency,
            'reference' => $this->reference,
            'notes' => $this->notes,
            'received_at' => $this->received_at?->toIso8601String(),
            'receipt' => $this->whenLoaded('receipt', fn () => $this->receipt === null ? null : [
                'id' => $this->receipt->id,
                'number' => $this->receipt->number,
                'total' => (float) $this->receipt->total,
                'issued_at' => $this->receipt->issued_at?->toIso8601String(),
                'verification_token' => $this->receipt->verificationToken?->token,
            ]),
            'allocations' => $this->whenLoaded('allocations', fn () => $this->allocations->map(fn ($a) => [
                'document_id' => $a->document_id,
                'amount' => (float) $a->amount,
            ])->all()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
