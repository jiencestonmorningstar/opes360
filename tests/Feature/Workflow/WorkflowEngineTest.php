<?php

namespace Tests\Feature\Workflow;

use App\Models\Role;
use App\Models\WorkflowDecision;
use App\Models\WorkflowInstance;
use App\Services\Workflow\WorkflowEngine;
use RuntimeException;

class WorkflowEngineTest extends WorkflowTestCase
{
    public function test_starting_a_workflow_assigns_the_first_step(): void
    {
        $manager = $this->memberAt(Role::MANAGER);
        $workflow = $this->workflow([['approver_mode' => 'role', 'approver_role' => Role::MANAGER]]);

        $instance = $this->engine()->start($this->expense(), $workflow, $this->owner);

        $this->assertSame('running', $instance->status);
        $this->assertSame(1, $instance->position);
        $this->assertSame([$manager->id], $instance->assignments->pluck('user_id')->all());
    }

    public function test_starting_records_the_submission(): void
    {
        $this->memberAt(Role::MANAGER);

        $instance = $this->engine()->start($this->expense(), $this->workflow([[]]), $this->owner);

        $this->assertSame('submitted', $instance->decisions->first()->action);
        $this->assertSame($this->owner->id, $instance->decisions->first()->user_id);
    }

    public function test_an_any_step_closes_on_the_first_approval(): void
    {
        $one = $this->memberAt(Role::MANAGER);
        $this->memberAt(Role::MANAGER);

        $instance = $this->engine()->start(
            $this->expense(),
            $this->workflow([['quorum' => 'any']]),
            $this->owner,
        );

        $this->engine()->act($instance, $one, 'approved');

        $this->assertSame('approved', $instance->fresh()->status);
    }

    public function test_an_all_step_waits_for_everybody(): void
    {
        $one = $this->memberAt(Role::MANAGER);
        $two = $this->memberAt(Role::MANAGER);

        $instance = $this->engine()->start(
            $this->expense(),
            $this->workflow([['quorum' => 'all']]),
            $this->owner,
        );

        $this->engine()->act($instance, $one, 'approved');
        $this->assertSame('running', $instance->fresh()->status);

        $this->engine()->act($instance->fresh(), $two, 'approved');
        $this->assertSame('approved', $instance->fresh()->status);
    }

    public function test_a_numeric_quorum_closes_when_it_is_met(): void
    {
        $one = $this->memberAt(Role::MANAGER);
        $two = $this->memberAt(Role::MANAGER);
        $this->memberAt(Role::MANAGER);

        $instance = $this->engine()->start(
            $this->expense(),
            $this->workflow([['quorum' => '2']]),
            $this->owner,
        );

        $this->engine()->act($instance, $one, 'approved');
        $this->assertSame('running', $instance->fresh()->status);

        $this->engine()->act($instance->fresh(), $two, 'approved');
        $this->assertSame('approved', $instance->fresh()->status);
    }

    public function test_approving_one_step_assigns_the_next(): void
    {
        $manager = $this->memberAt(Role::MANAGER);
        $accountant = $this->memberAt(Role::ACCOUNTANT);

        $instance = $this->engine()->start(
            $this->expense(),
            $this->workflow([
                ['approver_mode' => 'role', 'approver_role' => Role::MANAGER],
                ['approver_mode' => 'role', 'approver_role' => Role::ACCOUNTANT],
            ]),
            $this->owner,
        );

        $this->engine()->act($instance, $manager, 'approved');

        $instance = $instance->fresh();

        $this->assertSame('running', $instance->status);
        $this->assertSame(2, $instance->position);
        $this->assertSame(
            [$accountant->id],
            $instance->assignments()->pending()->pluck('user_id')->all(),
        );
    }

    /** A step whose condition does not apply is stepped over, not stalled on. */
    public function test_a_step_whose_condition_fails_is_skipped(): void
    {
        $manager = $this->memberAt(Role::MANAGER);
        $this->memberAt(Role::ACCOUNTANT);

        $instance = $this->engine()->start(
            $this->expense(5_000),
            $this->workflow([
                ['approver_mode' => 'role', 'approver_role' => Role::MANAGER],
                [
                    'approver_mode' => 'role',
                    'approver_role' => Role::ACCOUNTANT,
                    'conditions' => [['field' => 'total', 'operator' => '>=', 'value' => 10_000_000]],
                ],
            ]),
            $this->owner,
        );

        $this->engine()->act($instance, $manager, 'approved');

        $this->assertSame('approved', $instance->fresh()->status);
    }

    public function test_the_same_step_applies_when_its_condition_is_met(): void
    {
        $manager = $this->memberAt(Role::MANAGER);
        $accountant = $this->memberAt(Role::ACCOUNTANT);

        $instance = $this->engine()->start(
            $this->expense(50_000_000),
            $this->workflow([
                ['approver_mode' => 'role', 'approver_role' => Role::MANAGER],
                [
                    'approver_mode' => 'role',
                    'approver_role' => Role::ACCOUNTANT,
                    'conditions' => [['field' => 'total', 'operator' => '>=', 'value' => 10_000_000]],
                ],
            ]),
            $this->owner,
        );

        $this->engine()->act($instance, $manager, 'approved');

        $this->assertSame('running', $instance->fresh()->status);
        $this->assertSame(
            [$accountant->id],
            $instance->fresh()->assignments()->pending()->pluck('user_id')->all(),
        );
    }

    public function test_rejection_stops_the_instance_immediately(): void
    {
        $manager = $this->memberAt(Role::MANAGER);
        $this->memberAt(Role::ACCOUNTANT);

        $instance = $this->engine()->start(
            $this->expense(),
            $this->workflow([
                ['approver_mode' => 'role', 'approver_role' => Role::MANAGER],
                ['approver_mode' => 'role', 'approver_role' => Role::ACCOUNTANT],
            ]),
            $this->owner,
        );

        $this->engine()->act($instance, $manager, 'rejected', 'Not budgeted.');

        $instance = $instance->fresh();

        $this->assertSame('rejected', $instance->status);
        $this->assertSame(0, $instance->assignments()->pending()->count());
        $this->assertNotNull($instance->completed_at);
    }

    /** "No" and "not yet" are different answers and must stay different. */
    public function test_requesting_changes_returns_it_to_the_submitter(): void
    {
        $manager = $this->memberAt(Role::MANAGER);

        $instance = $this->engine()->start($this->expense(), $this->workflow([[]]), $this->owner);

        $this->engine()->act($instance, $manager, 'changes_requested', 'Attach the receipt.');

        $instance = $instance->fresh();

        $this->assertSame('changes_requested', $instance->status);
        $this->assertSame(0, $instance->assignments()->pending()->count());
        $this->assertNull($instance->completed_at);
    }

    public function test_a_returned_instance_can_be_resubmitted_from_the_first_step(): void
    {
        $manager = $this->memberAt(Role::MANAGER);

        $instance = $this->engine()->start($this->expense(), $this->workflow([[]]), $this->owner);
        $this->engine()->act($instance, $manager, 'changes_requested');

        $this->engine()->resubmit($instance->fresh(), $this->owner);

        $instance = $instance->fresh();

        $this->assertSame('running', $instance->status);
        $this->assertSame(1, $instance->position);
        $this->assertSame(1, $instance->assignments()->pending()->count());
    }

    public function test_an_approved_instance_cannot_be_resubmitted(): void
    {
        $manager = $this->memberAt(Role::MANAGER);

        $instance = $this->engine()->start($this->expense(), $this->workflow([[]]), $this->owner);
        $this->engine()->act($instance, $manager, 'approved');

        $this->expectException(RuntimeException::class);

        $this->engine()->resubmit($instance->fresh(), $this->owner);
    }

    public function test_somebody_who_was_not_asked_cannot_approve(): void
    {
        $this->memberAt(Role::MANAGER);
        $bystander = $this->memberAt(Role::CASHIER);

        $instance = $this->engine()->start($this->expense(), $this->workflow([[]]), $this->owner);

        $this->expectException(RuntimeException::class);

        $this->engine()->act($instance, $bystander, 'approved');
    }

    public function test_the_same_person_cannot_approve_twice(): void
    {
        $manager = $this->memberAt(Role::MANAGER);
        $this->memberAt(Role::MANAGER);

        $instance = $this->engine()->start(
            $this->expense(),
            $this->workflow([['quorum' => 'all']]),
            $this->owner,
        );

        $this->engine()->act($instance, $manager, 'approved');

        $this->expectException(RuntimeException::class);

        $this->engine()->act($instance->fresh(), $manager, 'approved');
    }

    public function test_a_finished_instance_refuses_further_decisions(): void
    {
        $manager = $this->memberAt(Role::MANAGER);

        $instance = $this->engine()->start($this->expense(), $this->workflow([[]]), $this->owner);
        $this->engine()->act($instance, $manager, 'approved');

        $this->expectException(RuntimeException::class);

        $this->engine()->act($instance->fresh(), $manager, 'approved');
    }

    /**
     * A workflow whose step resolves to nobody must not quietly approve
     * itself. It stops, visibly, so somebody fixes the workflow.
     */
    public function test_a_step_with_no_possible_approver_stalls_rather_than_passing(): void
    {
        $workflow = $this->workflow([['approver_mode' => 'role', 'approver_role' => Role::MANAGER]]);

        $instance = $this->engine()->start($this->expense(), $workflow, $this->owner);

        $this->assertSame('stalled', $instance->fresh()->status);
    }

    public function test_a_stalled_instance_can_be_resubmitted_once_somebody_can_approve(): void
    {
        $workflow = $this->workflow([['approver_mode' => 'role', 'approver_role' => Role::MANAGER]]);
        $instance = $this->engine()->start($this->expense(), $workflow, $this->owner);

        $this->assertSame('stalled', $instance->fresh()->status);

        $manager = $this->memberAt(Role::MANAGER);

        $this->engine()->resubmit($instance->fresh(), $this->owner);

        $this->assertSame('running', $instance->fresh()->status);
        $this->assertSame(
            [$manager->id],
            $instance->fresh()->assignments()->pending()->pluck('user_id')->all(),
        );
    }

    /** Editing a workflow must not rewrite what already happened. */
    public function test_a_decision_keeps_the_step_name_it_was_made_under(): void
    {
        $manager = $this->memberAt(Role::MANAGER);
        $workflow = $this->workflow([['name' => 'Manager review']]);

        $instance = $this->engine()->start($this->expense(), $workflow, $this->owner);
        $this->engine()->act($instance, $manager, 'approved');

        $workflow->steps->first()->update(['name' => 'Something else entirely']);

        $decision = WorkflowDecision::query()->where('action', 'approved')->first();

        $this->assertSame('Manager review', $decision->step_name);
    }

    public function test_a_decision_cannot_be_edited_or_deleted(): void
    {
        $manager = $this->memberAt(Role::MANAGER);
        $instance = $this->engine()->start($this->expense(), $this->workflow([[]]), $this->owner);
        $this->engine()->act($instance, $manager, 'approved');

        $decision = WorkflowDecision::query()->where('action', 'approved')->first();

        $this->expectException(RuntimeException::class);

        $decision->update(['action' => 'rejected']);
    }

    public function test_the_subject_can_find_its_own_approval(): void
    {
        $this->memberAt(Role::MANAGER);
        $expense = $this->expense();

        $this->engine()->start($expense, $this->workflow([[]]), $this->owner);

        $this->assertInstanceOf(WorkflowInstance::class, $expense->fresh()->approval());
        $this->assertTrue($expense->fresh()->isAwaitingApproval());
    }

    public function test_the_subject_knows_when_it_has_been_approved(): void
    {
        $manager = $this->memberAt(Role::MANAGER);
        $expense = $this->expense();

        $instance = $this->engine()->start($expense, $this->workflow([[]]), $this->owner);
        $this->engine()->act($instance, $manager, 'approved');

        $this->assertTrue($expense->fresh()->isApproved());
        $this->assertFalse($expense->fresh()->isAwaitingApproval());
    }

    public function test_cancelling_stops_everything_and_is_recorded(): void
    {
        $this->memberAt(Role::MANAGER);

        $instance = $this->engine()->start($this->expense(), $this->workflow([[]]), $this->owner);

        $this->engine()->cancel($instance, $this->owner, 'Raised in error.');

        $instance = $instance->fresh();

        $this->assertSame('cancelled', $instance->status);
        $this->assertSame(0, $instance->assignments()->pending()->count());
        $this->assertSame(1, $instance->decisions()->where('action', 'cancelled')->count());
    }

    // ── Delegation ────────────────────────────────────────────────────────

    public function test_an_approver_can_hand_their_decision_to_somebody_else(): void
    {
        $manager = $this->memberAt(Role::MANAGER);
        $deputy = $this->memberAt(Role::ACCOUNTANT);

        $instance = $this->engine()->start($this->expense(), $this->workflow([[]]), $this->owner);

        $this->engine()->delegate($instance, $manager, $deputy, 'On leave until Monday.');

        $this->assertSame(
            [$deputy->id],
            $instance->fresh()->assignments()->pending()->pluck('user_id')->all(),
        );
    }

    public function test_the_delegate_can_then_approve(): void
    {
        $manager = $this->memberAt(Role::MANAGER);
        $deputy = $this->memberAt(Role::ACCOUNTANT);

        $instance = $this->engine()->start($this->expense(), $this->workflow([[]]), $this->owner);
        $this->engine()->delegate($instance, $manager, $deputy);
        $this->engine()->act($instance->fresh(), $deputy, 'approved');

        $this->assertSame('approved', $instance->fresh()->status);
    }

    public function test_the_original_approver_can_no_longer_act_after_delegating(): void
    {
        $manager = $this->memberAt(Role::MANAGER);
        $deputy = $this->memberAt(Role::ACCOUNTANT);

        $instance = $this->engine()->start($this->expense(), $this->workflow([[]]), $this->owner);
        $this->engine()->delegate($instance, $manager, $deputy);

        $this->expectException(RuntimeException::class);

        $this->engine()->act($instance->fresh(), $manager, 'approved');
    }

    /** Delegation is a handover, not an escape: the record still says who was asked. */
    public function test_a_delegated_assignment_remembers_where_it_came_from(): void
    {
        $manager = $this->memberAt(Role::MANAGER);
        $deputy = $this->memberAt(Role::ACCOUNTANT);

        $instance = $this->engine()->start($this->expense(), $this->workflow([[]]), $this->owner);
        $this->engine()->delegate($instance, $manager, $deputy);

        $this->assertSame(
            $manager->id,
            $instance->fresh()->assignments()->pending()->first()->delegated_from,
        );

        $decision = $instance->fresh()->decisions()->where('action', 'delegated')->first();

        $this->assertSame($manager->id, $decision->user_id);
        $this->assertSame($deputy->id, $decision->delegated_to);
    }

    protected function engine(): WorkflowEngine
    {
        return app(WorkflowEngine::class);
    }
}
