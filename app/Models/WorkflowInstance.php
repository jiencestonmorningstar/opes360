<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One run of one workflow against one record.
 */
class WorkflowInstance extends Model
{
    use BelongsToCompany;
    use HasUlids;

    /** Terminal states. Nothing further may be recorded against these. */
    public const FINISHED = ['approved', 'rejected', 'cancelled'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo('subject');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(WorkflowAssignment::class);
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(WorkflowDecision::class)->orderBy('acted_at');
    }

    public function isFinished(): bool
    {
        return in_array($this->status, self::FINISHED, true);
    }

    public function currentStep(): ?WorkflowStep
    {
        return $this->workflow?->steps->firstWhere('position', $this->position);
    }

    /** @return array{label: string, tone: string} */
    public function state(): array
    {
        return match ($this->status) {
            'approved' => ['label' => 'Approved', 'tone' => 'positive'],
            'rejected' => ['label' => 'Rejected', 'tone' => 'negative'],
            'changes_requested' => ['label' => 'Changes requested', 'tone' => 'warning'],
            'stalled' => ['label' => 'Stalled', 'tone' => 'warning'],
            'cancelled' => ['label' => 'Cancelled', 'tone' => 'muted'],
            default => ['label' => 'In progress', 'tone' => 'neutral'],
        };
    }
}
