<?php

namespace App\Http\Resources;

use App\Support\WorkflowSubjects;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The wire shape of an approval path. Steps ride along whenever they are
 * loaded, in position order — the order the engine walks them.
 */
class WorkflowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'subject_type' => $this->subject_type,
            'subject_label' => WorkflowSubjects::label($this->subject_type),
            'is_active' => (bool) $this->is_active,
            'is_default' => (bool) $this->is_default,
            'steps' => $this->whenLoaded('steps', fn () => $this->steps->map(fn ($step) => [
                'id' => $step->id,
                'position' => $step->position,
                'name' => $step->name,
                'type' => $step->type,
                'approver_mode' => $step->approver_mode,
                'approver_role' => $step->approver_role,
                'approver_department_id' => $step->approver_department_id,
                'approver_user_id' => $step->approver_user_id,
                'quorum' => $step->quorum,
                'conditions' => $step->conditions,
                'due_days' => $step->due_days,
            ])->all()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
