<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The wire shape of a tenancy — the knot tying unit, tenant, lease and rent
 * schedule together. The ledger entry ids are deliberately absent: the books
 * are read through the accounting endpoints, not through a letting record.
 */
class TenancyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'property_unit_id' => $this->property_unit_id,
            'tenant_contact_id' => $this->tenant_contact_id,
            'contract_id' => $this->contract_id,
            'recurring_invoice_id' => $this->recurring_invoice_id,
            'rent' => (float) $this->rent,
            'deposit_amount' => (float) $this->deposit_amount,
            'deposit_retained' => $this->deposit_retained !== null ? (float) $this->deposit_retained : null,
            'moved_in_on' => $this->moved_in_on?->toDateString(),
            'moved_out_on' => $this->moved_out_on?->toDateString(),
            'unit' => $this->whenLoaded('unit', fn () => [
                'id' => $this->unit->id,
                'label' => $this->unit->label,
                'property_id' => $this->unit->property_id,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
