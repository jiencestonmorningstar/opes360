<?php

namespace App\Services\Workflow;

use App\Models\Department;
use App\Models\Workflow;
use App\Models\WorkflowInstance;
use App\Models\WorkflowStep;
use App\Support\CurrentCompany;
use App\Support\WorkflowApprovers;
use Illuminate\Support\Facades\DB;

/**
 * Keeps an approval that is already running out of the way of an edit.
 *
 * This is the one hard correctness question the admin screens raise. The
 * engine walks `workflow->steps` by position every time it advances, and an
 * assignment points at a step row. So editing a workflow in place changes the
 * rules of an approval that is halfway through:
 *
 *   - deleting a step cascades its assignments away, and the people who were
 *     asked simply stop being asked, with nothing to show it happened;
 *   - reordering steps moves an instance's position onto a different step, so
 *     an approval either repeats a step somebody already signed or skips one
 *     nobody did;
 *   - inserting a step at the front pushes every later step up one, with the
 *     same result;
 *   - changing who approves a step that has already been assigned leaves the
 *     old approver holding a decision the workflow no longer asks for.
 *
 * Refusing the edit was the alternative and it is the wrong one. A business
 * discovers a workflow is wrong precisely because something is stuck in it,
 * so "you cannot fix this until the thing it is blocking finishes" is a
 * deadlock dressed up as a safety rule.
 *
 * So: copy on write. The old definition is copied aside, the running
 * approvals are moved onto the copy, and the copy is never touched again.
 * They finish under exactly the rules they started under. The workflow the
 * administrator is editing keeps its own id, its name, its default flag and
 * its URL, so everything pointing at it still works and the next submission
 * gets the new rules.
 *
 * Decisions are deliberately NOT moved. They already carry a copy of the step
 * name and their step id nulls rather than cascades, because the schema
 * decided long before this screen existed that history must not be rewritten
 * by an edit. Moving them would be rewriting it.
 */
class WorkflowVersioning
{
    public function __construct(protected WorkflowApprovers $approvers) {}

    /** Approvals against this workflow that have not finished one way or another. */
    public function inFlight(Workflow $workflow): int
    {
        return $workflow->instances()
            ->whereNotIn('status', WorkflowInstance::FINISHED)
            ->count();
    }

    /**
     * Freeze the current definition if anything is still running against it.
     *
     * Call this BEFORE any change to the steps. Returns the frozen copy, or
     * null when there was nothing in flight and the edit is simply safe.
     */
    public function freeze(Workflow $workflow): ?Workflow
    {
        $running = $workflow->instances()
            ->whereNotIn('status', WorkflowInstance::FINISHED)
            ->get();

        if ($running->isEmpty()) {
            return null;
        }

        return DB::transaction(function () use ($workflow, $running) {
            $archive = Workflow::create([
                'company_id' => $workflow->company_id,
                'subject_type' => $workflow->subject_type,
                'archived_from_id' => $workflow->id,
                'name' => $workflow->name,
                'description' => $workflow->description,
                // Never offered and never chosen: this copy exists only to
                // finish what was already started under it.
                'is_active' => false,
                'is_default' => false,
                'created_by' => $workflow->created_by,
            ]);

            /** @var array<string, string> $map old step id => frozen step id */
            $map = [];

            foreach ($workflow->steps()->get() as $step) {
                $copy = WorkflowStep::create([
                    'company_id' => $step->company_id,
                    'workflow_id' => $archive->id,
                    // Everything set explicitly. Model::create() does not
                    // backfill a column default, so a copy that leaned on the
                    // schema would come back with 'approval'/'any'/no position
                    // and quietly differ from the rules it is meant to preserve.
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
                ]);

                $map[$step->id] = $copy->id;
            }

            foreach ($running as $instance) {
                $instance->forceFill(['workflow_id' => $archive->id])->save();

                foreach ($instance->assignments()->get() as $assignment) {
                    if (isset($map[$assignment->workflow_step_id])) {
                        $assignment->forceFill([
                            'workflow_step_id' => $map[$assignment->workflow_step_id],
                        ])->save();
                    }
                }
            }

            return $archive;
        });
    }

    /**
     * Steps that currently resolve to nobody.
     *
     * The engine stalls such a step rather than passing it, which is right and
     * is also completely silent — the approval simply stops, and the first
     * anybody hears is a fortnight later. So it is worth saying at the moment
     * the rule is written. It is a warning and never a refusal: a business may
     * well be defining the path for a role it is about to hire into.
     *
     * `owner`, `creator` and `manager` are not checked. They resolve against
     * the record being approved, which does not exist yet, so anything this
     * could say about them would be a guess.
     *
     * @return array<int, string>
     */
    public function warnings(Workflow $workflow): array
    {
        $companyId = app(CurrentCompany::class)->id();
        $warnings = [];

        if ($companyId === null) {
            return $warnings;
        }

        foreach ($workflow->steps()->get() as $step) {
            $warning = $this->warningFor($step, $companyId);

            if ($warning !== null) {
                $warnings[] = $warning;
            }
        }

        return $warnings;
    }

    public function warningFor(WorkflowStep $step, string $companyId): ?string
    {
        if ($step->approver_mode === 'role') {
            if ($step->approver_role === null) {
                return "“{$step->name}” does not say which role approves it, so nobody will be asked.";
            }

            if ($this->approvers->holdingRole($step->approver_role, $companyId)->isEmpty()) {
                return 'Nobody here holds the '.str_replace('-', ' ', $step->approver_role)
                    ." role, so “{$step->name}” will stop and wait rather than pass.";
            }
        }

        if ($step->approver_mode === 'department') {
            $department = Department::find($step->approver_department_id);

            if ($department === null) {
                return "“{$step->name}” does not name a department, so nobody will be asked.";
            }

            if ($department->manager_id === null) {
                return "{$department->name} has no manager, so “{$step->name}” will stop and wait rather than pass.";
            }
        }

        if ($step->approver_mode === 'user' && $step->approver_user_id === null) {
            return "“{$step->name}” does not name anybody, so it will stop and wait rather than pass.";
        }

        return null;
    }
}
