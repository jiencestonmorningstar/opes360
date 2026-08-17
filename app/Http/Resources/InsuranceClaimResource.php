<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** The wire shape of an insurance claim — one loss, from notification to a decision. */
class InsuranceClaimResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'insurance_policy_id' => $this->insurance_policy_id,
            'claim_number' => $this->claim_number,
            'status' => $this->status,
            'incident_on' => $this->incident_on?->toDateString(),
            'reported_on' => $this->reported_on?->toDateString(),
            'description' => $this->description,
            'claimed_amount' => $this->claimed_amount !== null ? (float) $this->claimed_amount : null,
            'settled_amount' => $this->settled_amount !== null ? (float) $this->settled_amount : null,
            'settled_on' => $this->settled_on?->toDateString(),
            'rejection_reason' => $this->rejection_reason,
            'assessment_notes' => $this->assessment_notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
