<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** The wire shape of an insurance policy — the cover, its dates, its money terms. */
class InsurancePolicyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'policy_number' => $this->policy_number,
            'status' => $this->status,
            'product_line' => $this->product_line,
            'holder_contact_id' => $this->holder_contact_id,
            'insurer_contact_id' => $this->insurer_contact_id,
            'premium' => $this->premium !== null ? (float) $this->premium : null,
            'currency' => $this->currency,
            'commission_percent' => $this->commission_percent !== null ? (float) $this->commission_percent : null,
            'covers_from' => $this->covers_from?->toDateString(),
            'covers_to' => $this->covers_to?->toDateString(),
            'notice_by' => $this->notice_by?->toDateString(),
            'renewal_type' => $this->renewal_type,
            'renewal_term_months' => $this->renewal_term_months,
            'notice_period_days' => $this->notice_period_days,
            'cancelled_on' => $this->cancelled_on?->toDateString(),
            'cancellation_reason' => $this->cancellation_reason,
            'owner_id' => $this->owner_id,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
