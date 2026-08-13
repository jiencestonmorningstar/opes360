<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One admission, held by one person.
 *
 * `verification_token` is here for the same reason the receipt's is on
 * PaymentResource: a caller that prints its own ticket must be able to print
 * the same QR the app would, or the door has nothing to scan. It is only ever
 * returned to somebody who already holds `events.view` for the company that
 * issued it.
 */
class TicketResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_id' => $this->event_id,
            'ticket_type_id' => $this->ticket_type_id,
            'ticket_type' => $this->whenLoaded('ticketType', fn () => $this->ticketType?->name),
            'serial' => $this->serial,
            'buyer_name' => $this->buyer_name,
            'buyer_email' => $this->buyer_email,
            'buyer_phone' => $this->buyer_phone,
            'price' => (float) $this->price,
            'status' => $this->status,
            'checked_in_at' => $this->checked_in_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'verification_token' => $this->whenLoaded('verificationToken', fn () => $this->verificationToken?->token),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
