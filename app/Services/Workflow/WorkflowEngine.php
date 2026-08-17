<?php

namespace App\Services\Workflow;

use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowAssignment;
use App\Models\WorkflowDecision;
use App\Models\WorkflowInstance;
use App\Models\WorkflowStep;
use App\Support\WorkflowApprovers;
use App\Support\WorkflowConditions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The one place an approval happens.
 *
 * Documents, Procurement, Expenses and HR all submit a record and read an
 * outcome through this. Four approval engines is the failure mode the brief
 * names outright, and the only way to avoid it is for there to be somewhere
 * obvious to go instead.
 */
class WorkflowEngine
{
    public function __construct(
        protected WorkflowApprovers $approvers,
        protected WorkflowConditions $conditions,
    ) {}

    public function start(Model $subject, Workflow $workflow, User $submitter): WorkflowInstance
    {
        return DB::transaction(function () use ($subject, $workflow, $submitter) {
            $instance = WorkflowInstance::create([
                'workflow_id' => $workflow->id,
                'subject_type' => $subject->getMorphClass(),
                'subject_id' => $subject->getKey(),
                'status' => 'running',
                'position' => 0,
                'started_by' => $submitter->id,
                'started_at' => now(),
            ]);

            $this->record($instance, null, $submitter, 'submitted', null, 'Submitted');

            $this->announce($instance, 'workflow.started', $subject, [
                'subject_type' => $subject->getMorphClass(),
                'subject_id' => $subject->getKey(),
            ]);

            return $this->advance($instance);
        });
    }

    /**
     * Record one person's decision, then see whether the step is finished.
     */
    public function act(
        WorkflowInstance $instance,
        User $user,
        string $action,
        ?string $comment = null,
    ): WorkflowInstance {
        return DB::transaction(function () use ($instance, $user, $action, $comment) {
            $assignment = $this->assignmentFor($instance, $user);
            $step = $assignment->step;

            $this->record($instance, $step, $user, $action, $comment, $step->name);

            $assignment->update(['status' => 'acted']);

            return match ($action) {
                'rejected' => $this->finish($instance, 'rejected'),
                'changes_requested' => $this->returnToSubmitter($instance),
                default => $this->closeStepIfSatisfied($instance, $step),
            };
        });
    }

    /**
     * Hand an outstanding decision to somebody else.
     *
     * A handover, not an escape. The decision log keeps the delegation and
     * the new assignment keeps `delegated_from`, so the record still shows who
     * was asked first — otherwise "the manager approved it" would be
     * indistinguishable from "the manager passed it to a friend".
     */
    public function delegate(
        WorkflowInstance $instance,
        User $from,
        User $to,
        ?string $comment = null,
    ): WorkflowInstance {
        return DB::transaction(function () use ($instance, $from, $to, $comment) {
            $assignment = $this->assignmentFor($instance, $from);

            $this->record(
                $instance,
                $assignment->step,
                $from,
                'delegated',
                $comment,
                $assignment->step->name,
                $to->id,
            );

            $assignment->update(['status' => 'delegated']);

            WorkflowAssignment::updateOrCreate(
                [
                    'workflow_instance_id' => $instance->id,
                    'workflow_step_id' => $assignment->workflow_step_id,
                    'user_id' => $to->id,
                ],
                [
                    'status' => 'pending',
                    'due_on' => $assignment->due_on,
                    'delegated_from' => $from->id,
                ],
            );

            return $instance->fresh();
        });
    }

    /** Send a returned or stalled record back round, from the beginning. */
    public function resubmit(WorkflowInstance $instance, User $submitter): WorkflowInstance
    {
        return DB::transaction(function () use ($instance, $submitter) {
            if (! in_array($instance->status, ['changes_requested', 'stalled'], true)) {
                throw new RuntimeException('Only a returned or stalled approval can be resubmitted.');
            }

            $instance->update(['status' => 'running', 'position' => 0]);

            $this->record($instance, null, $submitter, 'submitted', null, 'Resubmitted');

            return $this->advance($instance->fresh());
        });
    }

    public function cancel(WorkflowInstance $instance, User $user, ?string $comment = null): WorkflowInstance
    {
        return DB::transaction(function () use ($instance, $user, $comment) {
            if ($instance->isFinished()) {
                throw new RuntimeException('This approval is already finished.');
            }

            $step = $instance->currentStep();

            $this->record($instance, $step, $user, 'cancelled', $comment, $step?->name ?? 'Cancelled');

            return $this->finish($instance, 'cancelled');
        });
    }

    /**
     * The one authorisation check the engine makes.
     *
     * Being asked IS the permission — requiring a second one would mean an
     * approver the engine itself assigned could not act. Somebody acting
     * without an assignment is either a bug or an attempt, and both deserve
     * to surface rather than to no-op.
     */
    protected function assignmentFor(WorkflowInstance $instance, User $user): WorkflowAssignment
    {
        if ($instance->isFinished()) {
            throw new RuntimeException('This approval is already finished.');
        }

        $assignment = $instance->assignments()->pending()->where('user_id', $user->id)->first();

        if ($assignment === null) {
            throw new RuntimeException('You have not been asked to act on this.');
        }

        return $assignment;
    }

    /**
     * Move to the next step that actually applies, and assign it.
     *
     * Steps whose conditions do not match are stepped over rather than
     * stalled on — that is precisely what "if the amount is over ten million"
     * means.
     */
    protected function advance(WorkflowInstance $instance): WorkflowInstance
    {
        $steps = $instance->workflow->steps;

        if ($steps->isEmpty()) {
            return $this->finish($instance, 'approved');
        }

        $subject = $instance->subject;
        $last = (int) $steps->max('position');

        for ($position = $instance->position + 1; $position <= $last; $position++) {
            $step = $steps->firstWhere('position', $position);

            if ($step === null || ! $this->conditions->passes($step, $subject)) {
                continue;
            }

            /*
             * §5 item 3 of the Documents completion plan: a compose_document
             * step asks nobody for a decision, so it is handled and stepped
             * past here rather than falling into the approver-assignment
             * path below — the one branch this method gains for the new
             * type, additive alongside review/approval/signature/task, which
             * are untouched.
             */
            if ($step->type === 'compose_document') {
                app(ComposeDocumentStep::class)->handle($instance, $step);

                $instance->update(['position' => $position, 'status' => 'running']);

                continue;
            }

            $approvers = $this->approvers->resolve($step, $subject);

            /*
             * A step nobody can fill stops the instance where it is. It must
             * never be treated as satisfied: an approval that approves itself
             * because its approver left the company is worse than a stuck
             * one, because the stuck one gets noticed and fixed.
             */
            if ($approvers->isEmpty()) {
                $instance->update(['status' => 'stalled', 'position' => $position]);

                // Announced, so somebody can be told rather than discovering
                // it when the invoice is a fortnight old.
                $this->announce($instance, 'workflow.stalled', $subject, ['step' => $step->name]);

                return $instance->fresh();
            }

            $instance->update(['position' => $position, 'status' => 'running']);

            foreach ($approvers as $approver) {
                WorkflowAssignment::updateOrCreate(
                    [
                        'workflow_instance_id' => $instance->id,
                        'workflow_step_id' => $step->id,
                        'user_id' => $approver->id,
                    ],
                    [
                        'status' => 'pending',
                        'due_on' => $step->due_days !== null
                            ? now()->addDays($step->due_days)->toDateString()
                            : null,
                    ],
                );
            }

            // Catalogued since the engine's own beginning but never actually
            // raised — a step reaching an approver is exactly "somebody was
            // asked to look at this", which TranslateDocumentWorkflowEvents
            // restates as document.review.requested for a document subject.
            $this->announce($instance, 'workflow.step.assigned', $subject, ['step' => $step->name]);

            return $instance->fresh();
        }

        // Nothing left to ask: every applicable step is satisfied.
        return $this->finish($instance, 'approved');
    }

    protected function closeStepIfSatisfied(WorkflowInstance $instance, WorkflowStep $step): WorkflowInstance
    {
        $assigned = $instance->assignments()->where('workflow_step_id', $step->id)->count();

        $approvals = $instance->decisions()
            ->where('workflow_step_id', $step->id)
            ->where('action', 'approved')
            ->count();

        if ($approvals < $step->requiredApprovals($assigned)) {
            return $instance->fresh();
        }

        $instance->assignments()
            ->pending()
            ->where('workflow_step_id', $step->id)
            ->update(['status' => 'superseded']);

        return $this->advance($instance->fresh());
    }

    protected function returnToSubmitter(WorkflowInstance $instance): WorkflowInstance
    {
        $instance->assignments()->pending()->update(['status' => 'superseded']);

        /*
         * No completed_at: this is "not yet", not "no". Collapsing the two
         * would lose the difference between a rejected record and one that
         * needs a receipt attaching.
         */
        $instance->update(['status' => 'changes_requested']);

        $this->announce($instance, 'workflow.changes_requested', $instance->subject);

        return $instance->fresh();
    }

    protected function finish(WorkflowInstance $instance, string $status): WorkflowInstance
    {
        $instance->assignments()->pending()->update(['status' => 'superseded']);

        $instance->update(['status' => $status, 'completed_at' => now()]);

        // workflow.approved / .rejected / .cancelled — the events a rule
        // listens to in order to advance whatever comes next.
        $this->announce($instance, 'workflow.'.$status, $instance->subject);

        return $instance->fresh();
    }

    /**
     * Announces through the actual approvable record, never through the
     * WorkflowInstance wrapper.
     *
     * The instance is infrastructure — a document, an expense and a project
     * all produce one indistinguishably. An event whose subject was the
     * instance would carry that indistinguishability into every listener,
     * including TranslateDocumentWorkflowEvents, which exists specifically to
     * tell a document's approval apart from anyone else's. Guarded with
     * method_exists rather than an interface, because not every Approvable
     * model is guaranteed to also emit domain events — Approvable and
     * EmitsDomainEvents are deliberately separate traits.
     */
    protected function announce(WorkflowInstance $instance, string $event, ?Model $subject, array $context = []): void
    {
        if ($subject === null || ! method_exists($subject, 'emitDomainEvent')) {
            return;
        }

        $subject->emitDomainEvent($event, $context + ['workflow_instance_id' => $instance->id]);
    }

    protected function record(
        WorkflowInstance $instance,
        ?WorkflowStep $step,
        User $user,
        string $action,
        ?string $comment,
        string $fallbackName,
        ?int $delegatedTo = null,
    ): WorkflowDecision {
        return WorkflowDecision::create([
            'workflow_instance_id' => $instance->id,
            'workflow_step_id' => $step?->id,
            'step_name' => $step?->name ?? $fallbackName,
            'user_id' => $user->id,
            'action' => $action,
            'comment' => $comment,
            'delegated_to' => $delegatedTo,
            'acted_at' => now(),
        ]);
    }
}
