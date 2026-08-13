<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The wire shape of a ticketed event.
 *
 * `public_url` is included rather than left for the caller to assemble: the
 * share token is random precisely so that links cannot be derived from ids,
 * and a caller building its own URL would be guessing at a route it does not
 * own. `selling` is the same question `isSelling()` answers for the public
 * page — published *and* not yet started — so a caller does not have to
 * re-derive that rule and get it subtly wrong.
 */
class EventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'venue' => $this->venue,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'status' => $this->status,
            'selling' => $this->isSelling(),
            'public_url' => $this->publicUrl(),
            'ticket_types' => TicketTypeResource::collection($this->whenLoaded('ticketTypes')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
