<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The wire shape of a library document.
 *
 * Explicit rather than a model dump — a resource that returns the model's
 * attributes is a promise to keep every future column public, and
 * `content_hash` in particular has no business leaving this endpoint.
 */
class BusinessDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'reference' => $this->reference,
            'recipient' => $this->recipient,
            'template' => $this->template,
            'kind' => $this->kind,
            'kind_label' => $this->kindLabel(),
            'description' => $this->description,
            'security' => $this->security,
            'language' => $this->language,
            'tags' => $this->tags ?? [],
            'folder_id' => $this->folder_id,
            'department_id' => $this->department_id,
            'project_id' => $this->project_id,
            'owner_id' => $this->owner_id,
            'status' => $this->status,
            'expires_on' => $this->expires_on?->toDateString(),
            'issued_at' => $this->issued_at?->toIso8601String(),
            'voided_at' => $this->voided_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
