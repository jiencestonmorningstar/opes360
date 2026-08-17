<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The wire shape of a shipment.
 *
 * `tracking_url` is included: it is the link the parties to this shipment are
 * given anyway, and a caller booking over the API is exactly who has to hand
 * it to the receiver. Events appear when loaded — the same story the public
 * tracking page tells.
 */
class ShipmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => $this->status,
            'sender_id' => $this->sender_id,
            'receiver_id' => $this->receiver_id,
            'cargo_description' => $this->cargo_description,
            'weight_kg' => $this->weight_kg !== null ? (float) $this->weight_kg : null,
            'declared_value' => $this->declared_value !== null ? (float) $this->declared_value : null,
            'from_location' => $this->from_location,
            'to_location' => $this->to_location,
            'freight_amount' => $this->freight_amount !== null ? (float) $this->freight_amount : null,
            'tracking_url' => $this->trackingUrl(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'document_id' => $this->document_id,
            'pod_document_id' => $this->pod_document_id,
            'events' => $this->whenLoaded('events', fn () => $this->events->map(fn ($event) => [
                'status' => $event->status,
                'note' => $event->note,
                'happened_at' => $event->happened_at?->toIso8601String(),
            ])->all()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
