<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * What somebody did, and when. The audit trail the brief requires.
 *
 * Immutable by construction: written once, never updated, never deleted. It
 * carries a copy of the step's name so that renaming a workflow afterwards
 * cannot rewrite the history of what was approved under the old one —
 * "approved by the Finance Manager" has to keep saying that.
 */
class WorkflowDecision extends Model
{
    use BelongsToCompany;
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'acted_at' => 'datetime',
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

    public function delegatedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegated_to');
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new RuntimeException('A workflow decision cannot be edited. Record a new one.');
        });

        static::deleting(function () {
            throw new RuntimeException('A workflow decision cannot be deleted.');
        });
    }
}
