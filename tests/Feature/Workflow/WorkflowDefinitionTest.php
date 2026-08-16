<?php

namespace Tests\Feature\Workflow;

use App\Models\Expense;
use App\Models\Role;
use App\Models\Workflow;
use App\Models\WorkflowStep;

class WorkflowDefinitionTest extends WorkflowTestCase
{
    public function test_a_workflow_belongs_to_a_company_and_a_subject_type(): void
    {
        $workflow = $this->workflow([[]]);

        $this->assertSame($this->company->id, $workflow->company_id);
        $this->assertSame(Expense::class, $workflow->subject_type);
    }

    public function test_steps_come_back_in_order(): void
    {
        $workflow = $this->workflow([
            ['name' => 'Manager review'],
            ['name' => 'Finance approval'],
            ['name' => 'Director sign-off'],
        ]);

        $this->assertSame(
            ['Manager review', 'Finance approval', 'Director sign-off'],
            $workflow->steps->pluck('name')->all(),
        );
    }

    /** Rows, not JSON: a decision has to be able to point at the step it was made on. */
    public function test_a_step_is_a_row_of_its_own(): void
    {
        $workflow = $this->workflow([[]]);

        $this->assertInstanceOf(WorkflowStep::class, $workflow->steps->first());
        $this->assertDatabaseCount('workflow_steps', 1);
    }

    public function test_a_step_records_how_many_approvals_it_needs(): void
    {
        $workflow = $this->workflow([
            ['quorum' => 'all'],
            ['quorum' => 'any'],
            ['quorum' => '2'],
        ]);

        $this->assertSame(['all', 'any', '2'], $workflow->steps->pluck('quorum')->all());
    }

    public function test_a_quorum_larger_than_the_people_available_does_not_stall(): void
    {
        $step = $this->workflow([['quorum' => '5']])->steps->first();

        $this->assertSame(2, $step->requiredApprovals(2));
    }

    public function test_any_needs_one_and_all_needs_everybody(): void
    {
        $steps = $this->workflow([['quorum' => 'any'], ['quorum' => 'all']])->steps;

        $this->assertSame(1, $steps[0]->requiredApprovals(4));
        $this->assertSame(4, $steps[1]->requiredApprovals(4));
    }

    public function test_only_one_workflow_per_subject_type_can_be_the_default(): void
    {
        $this->workflow([[]], ['name' => 'First', 'is_default' => true]);
        $this->workflow([[]], ['name' => 'Second', 'is_default' => true]);

        $this->assertSame(
            1,
            Workflow::query()->where('subject_type', Expense::class)->where('is_default', true)->count(),
        );
        $this->assertSame('Second', Workflow::query()->where('is_default', true)->value('name'));
        $this->assertTrue(Workflow::defaultFor(Expense::class)->is(
            Workflow::query()->where('name', 'Second')->first()
        ));
    }

    public function test_an_inactive_workflow_is_not_offered(): void
    {
        $this->workflow([[]], ['name' => 'Retired', 'is_active' => false]);
        $this->workflow([[]], ['name' => 'Current']);

        $this->assertSame(2, Workflow::query()->count());
        $this->assertSame(1, Workflow::active()->count());
    }

    public function test_deleting_a_workflow_takes_its_steps(): void
    {
        $workflow = $this->workflow([[], []]);

        $workflow->forceDelete();

        $this->assertDatabaseCount('workflow_steps', 0);
    }

    public function test_an_approver_rule_can_name_a_role_the_owner_or_a_person(): void
    {
        $workflow = $this->workflow([
            ['approver_mode' => 'role', 'approver_role' => Role::ACCOUNTANT],
            ['approver_mode' => 'owner'],
            ['approver_mode' => 'user', 'approver_user_id' => $this->owner->id],
        ]);

        $this->assertSame(
            ['role', 'owner', 'user'],
            $workflow->steps->pluck('approver_mode')->all(),
        );
    }

    /** Switching a module off must not delete the approvals it already ran. */
    public function test_workflows_are_not_a_switchable_module(): void
    {
        $this->assertArrayNotHasKey('workflows', config('modules'));
    }
}
