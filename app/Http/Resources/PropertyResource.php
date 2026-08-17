<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** The wire shape of a managed property, with its units when they were loaded. */
class PropertyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'kind' => $this->kind,
            'address' => $this->address,
            'landlord_contact_id' => $this->landlord_contact_id,
            'commission_percent' => $this->commission_percent !== null ? (float) $this->commission_percent : null,
            'notes' => $this->notes,
            'units' => $this->whenLoaded('units', fn () => $this->units->map(fn ($unit) => [
                'id' => $unit->id,
                'label' => $unit->label,
                'status' => $unit->status,
                'target_rent' => $unit->target_rent !== null ? (float) $unit->target_rent : null,
            ])->all()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
