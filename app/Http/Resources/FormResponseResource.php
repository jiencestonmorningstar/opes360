<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One submission.
 *
 * Answers stay keyed by field id, exactly as stored. Flattening them to labels
 * here would make a response unreadable the moment somebody renames a field,
 * which is the failure the storage format was chosen to avoid; a caller that
 * wants labels has the form's `fields` to join against.
 */
class FormResponseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'form_id' => $this->form_id,
            'answers' => $this->answers ?? [],
            'submitted_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
