<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\EmitsDomainEvents;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProjectTask extends Model
{
    use BelongsToCompany;
    use EmitsDomainEvents;
    use HasUlids;
    use SoftDeletes;

    public const STATUSES = [
        'todo' => 'To do',
        'in_progress' => 'In progress',
        'blocked' => 'Blocked',
        'done' => 'Done',
    ];

    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'due_on' => 'date',
            'completed_at' => 'datetime',
            'estimated_hours' => 'decimal:2',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * The milestone it was grouped under, if any.
     *
     * Nullable and set-null-on-delete: a task outlives the milestone it was
     * filed under, the same reasoning a Documents folder already follows —
     * grouping is an arrangement, not a container, and losing the work to a
     * deleted milestone would be unrecoverable.
     */
    public function milestone(): BelongsTo
    {
        return $this->belongsTo(ProjectMilestone::class, 'milestone_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function timeEntries(): HasMany
    {
        return $this->hasMany(ProjectTimeEntry::class, 'task_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', '!=', 'done');
    }

    public function scopeAssignedTo(Builder $query, User $user): Builder
    {
        return $query->where('assignee_id', $user->id);
    }

    public function isOverdue(): bool
    {
        return $this->status !== 'done' && $this->due_on !== null && $this->due_on->isPast();
    }

    public function loggedHours(): float
    {
        return (float) $this->timeEntries()->sum('hours');
    }
}
