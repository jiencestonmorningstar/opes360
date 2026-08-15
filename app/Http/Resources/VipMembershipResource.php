<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One membership, on the terms it was sold under.
 *
 * The tier is named but its current price and rate are not read through: a
 * tier repriced since the sale must not change what this member is reported as
 * holding. `is_active` asks the dates rather than the status column, for the
 * same reason the model does — the nightly sweep may not have run.
 */
class VipMembershipResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'contact_id' => $this->contact_id,
            'contact_name' => $this->whenLoaded('contact', fn () => $this->contact?->displayName()),
            'tier_name' => $this->tier_name,
            'discount_percent' => (float) $this->discount_percent,
            'price_paid' => (float) $this->price_paid,
            'currency' => $this->currency,
            'starts_on' => $this->starts_on?->toDateString(),
            'ends_on' => $this->ends_on?->toDateString(),
            'status' => $this->status,
            'is_active' => $this->isActive(),
            // The invoice that sold it, so a caller can follow the money.
            'document_id' => $this->document_id,
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancelled_reason' => $this->cancelled_reason,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
