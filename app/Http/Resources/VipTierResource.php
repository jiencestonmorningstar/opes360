<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** A tier a business currently offers, or once did. */
class VipTierResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'price' => (float) $this->price,
            'currency' => $this->currency,
            'period_months' => (int) $this->period_months,
            'discount_percent' => (float) $this->discount_percent,
            // Free text honoured by staff, not enforced by the system. Said
            // plainly here so an integration does not build logic on it.
            'perks' => $this->perks,
            'is_active' => (bool) $this->is_active,
        ];
    }
}
