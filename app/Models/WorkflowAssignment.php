<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\EmitsDomainEvents;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's outstanding action.
 *
 * Assignments are current; decisions are forever. This row is closed as the
 * instance advances — the permanent record of what happened is a
 * WorkflowDecision, and that one is never modified.
 */
class WorkflowAssignment extends Model
{
    use BelongsToCompany;
    use EmitsDomainEvents;
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'due_on' => 'date',
        ];
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(WorkflowInstance::class, 'workflow_instance_id');
    }

    public function step(): BelongsTo
    {
        return $this->belongsTo(WorkflowStep::class, 'workflow_step_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function delegatedFrom(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegated_from');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id);
    }

    public function isOverdue(): bool
    {
        return $this->due_on !== null
            && $this->status === 'pending'
            && $this->due_on->isPast();
    }
}
