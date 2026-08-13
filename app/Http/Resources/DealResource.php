<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The wire shape of a deal.
 *
 * Explicit rather than a model dump: a resource that returns the model's
 * attributes is a promise to keep every future column public.
 */
class DealResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'stage' => $this->stage,
            'value' => (float) $this->value,
            'currency' => $this->currency,
            'display_name' => $this->displayName(),
            'contact_id' => $this->contact_id,
            'lead_name' => $this->lead_name,
            'lead_phone' => $this->lead_phone,
            'notes' => $this->notes,
            'owner_id' => $this->owner_id,
            'expected_close_on' => $this->expected_close_on?->toDateString(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'lost_reason' => $this->lost_reason,
            'document_id' => $this->document_id,
            'is_open' => $this->isOpen(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
