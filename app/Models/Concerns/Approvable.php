<?php

namespace App\Models\Concerns;

use App\Models\WorkflowInstance;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * What a business model gains by being approvable.
 *
 * Deliberately thin. A module submits a record and reads an outcome; it never
 * touches assignments, steps or quorums. That thinness is the whole point of
 * having one engine rather than one per module.
 */
trait Approvable
{
    public function workflowInstances(): MorphMany
    {
        return $this->morphMany(WorkflowInstance::class, 'subject')->latest();
    }

    /** The current run, or the most recent finished one. */
    public function approval(): ?WorkflowInstance
    {
        return $this->workflowInstances()->first();
    }

    public function isAwaitingApproval(): bool
    {
        return $this->approval()?->status === 'running';
    }

    public function isApproved(): bool
    {
        return $this->approval()?->status === 'approved';
    }

    /**
     * Which column holds "who raised this".
     *
     * Models disagree — expenses record `recorded_by`, documents `created_by`
     * — so each answers for itself rather than the engine carrying a map of
     * every table in the product. Override in a model whose column differs.
     */
    protected function workflowCreatorColumn(): string
    {
        return 'created_by';
    }

    public function workflowCreatorId(): mixed
    {
        return $this->getAttribute($this->workflowCreatorColumn());
    }
}
