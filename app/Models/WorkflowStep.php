<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step of a workflow: who acts, how many of them, and when it applies.
 *
 * A row rather than an entry in a JSON blob, because assignments and
 * decisions point at it. A step buried inside JSON cannot be referenced, so
 * approval history would break the first time somebody edited a workflow.
 */
class WorkflowStep extends Model
{
    use BelongsToCompany;
    use HasUlids;

    public const TYPES = [
        'review' => 'Review',
        'approval' => 'Approval',
        'signature' => 'Signature',
        'task' => 'Task',
    ];

    public const APPROVER_MODES = [
        'role' => 'Anyone holding a role',
        'department' => 'A department’s manager',
        'user' => 'A named person',
        'owner' => 'The business owner',
        'manager' => 'The submitter’s department manager',
        'creator' => 'Whoever raised the record',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'conditions' => 'array',
        ];
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    public function approverDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'approver_department_id');
    }

    public function approverUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_user_id');
    }

    /**
     * How many approvals close this step, given how many people were asked.
     *
     * 'any' is 1 rather than 0 — a step nobody has to approve is not a step.
     * A number larger than the people available is capped at that many, so a
     * quorum of 3 with 2 approvers closes on 2 rather than stalling forever
     * on an arithmetic impossibility.
     */
    public function requiredApprovals(int $assigned): int
    {
        return match (true) {
            $this->quorum === 'all' => max(1, $assigned),
            $this->quorum === 'any' => 1,
            default => max(1, min((int) $this->quorum, max(1, $assigned))),
        };
    }
}
