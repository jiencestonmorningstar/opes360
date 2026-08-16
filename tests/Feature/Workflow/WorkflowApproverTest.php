<?php

namespace Tests\Feature\Workflow;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Role;
use App\Models\WorkflowStep;
use App\Support\WorkflowApprovers;

class WorkflowApproverTest extends WorkflowTestCase
{
    public function test_a_role_step_resolves_to_everyone_holding_that_role(): void
    {
        $one = $this->memberAt(Role::MANAGER);
        $two = $this->memberAt(Role::MANAGER);
        $this->memberAt(Role::CASHIER);

        $step = $this->step(['approver_mode' => 'role', 'approver_role' => Role::MANAGER]);

        $this->assertEqualsCanonicalizing(
            [$one->id, $two->id],
            $this->resolve($step)->pluck('id')->all(),
        );
    }

    public function test_a_named_person_resolves_to_themselves(): void
    {
        $person = $this->memberAt(Role::ACCOUNTANT);

        $step = $this->step(['approver_mode' => 'user', 'approver_user_id' => $person->id]);

        $this->assertSame([$person->id], $this->resolve($step)->pluck('id')->all());
    }

    public function test_an_owner_step_resolves_to_the_business_owner(): void
    {
        $step = $this->step(['approver_mode' => 'owner']);

        $this->assertSame([$this->owner->id], $this->resolve($step)->pluck('id')->all());
    }

    public function test_a_department_step_resolves_to_that_departments_manager(): void
    {
        $manager = $this->memberAt(Role::MANAGER);
        $department = Department::create(['name' => 'Finance', 'manager_id' => $manager->id]);

        $step = $this->step([
            'approver_mode' => 'department',
            'approver_department_id' => $department->id,
        ]);

        $this->assertSame([$manager->id], $this->resolve($step)->pluck('id')->all());
    }

    /**
     * A step nobody can fill must resolve to nobody. An approval that
     * approves itself because the approver left is worse than a stuck one:
     * the stuck one gets noticed.
     */
    public function test_a_department_with_no_manager_resolves_to_nobody(): void
    {
        $department = Department::create(['name' => 'Finance']);

        $step = $this->step([
            'approver_mode' => 'department',
            'approver_department_id' => $department->id,
        ]);

        $this->assertTrue($this->resolve($step)->isEmpty());
    }

    public function test_a_person_who_has_left_the_company_is_not_an_approver(): void
    {
        $manager = $this->memberAt(Role::MANAGER);
        $this->company->users()->updateExistingPivot($manager->id, ['status' => 'removed']);

        $step = $this->step(['approver_mode' => 'role', 'approver_role' => Role::MANAGER]);

        $this->assertTrue($this->resolve($step)->isEmpty());
    }

    public function test_a_creator_step_resolves_to_whoever_raised_the_record(): void
    {
        $step = $this->step(['approver_mode' => 'creator']);

        $this->assertSame([$this->owner->id], $this->resolve($step)->pluck('id')->all());
    }

    public function test_a_manager_step_resolves_through_the_submitters_department(): void
    {
        $boss = $this->memberAt(Role::MANAGER);
        $department = Department::create(['name' => 'Operations', 'manager_id' => $boss->id]);

        Employee::create([
            'first_name' => 'Aïcha',
            'last_name' => 'Njoya',
            'status' => 'active',
            'user_id' => $this->owner->id,
            'department_id' => $department->id,
        ]);

        $step = $this->step(['approver_mode' => 'manager']);

        $this->assertSame([$boss->id], $this->resolve($step)->pluck('id')->all());
    }

    public function test_a_submitter_with_no_employee_record_has_no_manager(): void
    {
        $step = $this->step(['approver_mode' => 'manager']);

        $this->assertTrue($this->resolve($step)->isEmpty());
    }

    /** An unknown mode must not quietly mean "everybody". */
    public function test_an_unrecognised_mode_resolves_to_nobody(): void
    {
        $this->memberAt(Role::MANAGER);

        $step = $this->step(['approver_mode' => 'whatever']);

        $this->assertTrue($this->resolve($step)->isEmpty());
    }

    protected function step(array $attributes = []): WorkflowStep
    {
        return $this->workflow([$attributes])->steps->first();
    }

    protected function resolve(WorkflowStep $step)
    {
        return app(WorkflowApprovers::class)->resolve($step, $this->expense());
    }
}
