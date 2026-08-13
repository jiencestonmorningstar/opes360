<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One tier of admission.
 *
 * `remaining` is null when the type is unlimited rather than a large number,
 * because "how many are left" and "there is no limit" are different answers
 * and a caller that treats the second as a count will eventually print one.
 */
class TicketTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_id' => $this->event_id,
            'name' => $this->name,
            'price' => (float) $this->price,
            'quantity' => $this->quantity,
            'sold' => (int) $this->sold,
            'remaining' => $this->remaining(),
            'sold_out' => $this->isSoldOut(),
            'sort' => (int) $this->sort,
        ];
    }
}
