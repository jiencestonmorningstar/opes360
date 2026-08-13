<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The wire shape of a form.
 *
 * Field definitions come back normalised through `fieldDefinitions()` rather
 * than as the raw JSON column, so a caller reading responses keyed by field id
 * sees exactly the ids and types the builder and the public page work with.
 * `response_count` appears only when it was counted — a list that eagerly
 * counted responses for every form would run a query per row for a number most
 * callers never look at.
 */
class FormResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status,
            'open' => $this->isOpen(),
            'public_url' => $this->publicUrl(),
            'fields' => $this->fieldDefinitions(),
            'response_count' => $this->whenCounted('responses'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
