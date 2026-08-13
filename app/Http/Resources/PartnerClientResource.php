<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A business in a secretariat's client book.
 *
 * The invite token is not here. It is a capability — whoever holds it can
 * claim the referral for this client — and a list endpoint that handed one out
 * per row would turn a read scope into a way to redirect somebody else's
 * commission. It is issued deliberately, by the screen that shows it once.
 */
class PartnerClientResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'contact_name' => $this->contact_name,
            'phone' => $this->phone,
            'email' => $this->email,
            'industry' => $this->industry,
            'city' => $this->city,
            'notes' => $this->notes,
            // Whether this client has become a paying business, which is the
            // thing that turns a name in a book into commission.
            'converted' => $this->converted_at !== null,
            'converted_at' => $this->converted_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
