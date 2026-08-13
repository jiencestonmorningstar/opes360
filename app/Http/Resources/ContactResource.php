<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The wire shape of a contact.
 *
 * `tax_id` is deliberately absent. It is a taxpayer identifier, encrypted at
 * rest, and nothing outside the app has yet needed to read one back — so it
 * can be written and not returned, which is the cheaper mistake to correct
 * later than having handed it out for a year first.
 */
class ContactResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'name' => $this->name,
            'company_name' => $this->company_name,
            'email' => $this->email,
            'phones' => $this->phones ?? [],
            'whatsapp' => $this->whatsapp,
            'address' => $this->address ?? [],
            'balance' => (float) $this->balance,
            'credit_limit' => $this->credit_limit !== null ? (float) $this->credit_limit : null,
            'payment_terms_days' => $this->payment_terms_days,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
