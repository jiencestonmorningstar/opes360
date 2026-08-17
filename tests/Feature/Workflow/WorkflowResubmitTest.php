<?php

namespace Tests\Feature\Workflow;

use App\Livewire\Workflow\Inbox;
use App\Models\Role;
use App\Services\Workflow\WorkflowEngine;
use Livewire\Livewire;

/**
 * A stalled approval can be recovered.
 *
 * The engine stalls an instance when a step has no resolvable approver, and
 * counts it on the executive report as alarming — which was permanent, because
 * WorkflowEngine::resubmit() had no caller anywhere. The Inbox is where it is
 * offered: a stalled instance has no pending assignment, so it can never
 * appear in anybody's "waiting on you" list, and the person it is actually
 * waiting on is its submitter.
 */
class WorkflowResubmitTest extends WorkflowTestCase
{
    /** A workflow whose one step names a role nobody holds — it stalls on start. */
    protected function stalledInstance()
    {
        $workflow = $this->workflow([
            ['name' => 'A cashier checks it', 'approver_mode' => 'role', 'approver_role' => Role::CASHIER],
        ], ['is_default' => true]);

        return app(WorkflowEngine::class)->start($this->expense(), $workflow, $this->owner);
    }

    public function test_the_submitter_sees_their_stalled_approval_and_can_resubmit_it(): void
    {
        $instance = $this->stalledInstance();
        $this->assertSame('stalled', $instance->status);

        $this->actingAs($this->owner);

        // Somebody now holds the role, so the resubmit can actually advance.
        $cashier = $this->memberAt(Role::CASHIER);

        Livewire::test(Inbox::class)
            ->assertSee('Stuck approvals')
            ->assertSee('Generator fuel')
            ->call('resubmit', $instance->id)
            ->assertHasNoErrors();

        $instance->refresh();
        $this->assertSame('running', $instance->status);
        $this->assertTrue($instance->assignments()->pending()->where('user_id', $cashier->id)->exists());
    }

    public function test_resubmitting_with_the_step_still_unfillable_says_so_and_stays_stalled(): void
    {
        $instance = $this->stalledInstance();

        $this->actingAs($this->owner);

        Livewire::test(Inbox::class)
            ->call('resubmit', $instance->id)
            ->assertHasErrors('stuck');

        $this->assertSame('stalled', $instance->fresh()->status);
    }

    public function test_somebody_elses_stalled_approval_is_neither_shown_nor_resubmittable(): void
    {
        $instance = $this->stalledInstance();

        // An accountant neither submitted it nor manages workflows.
        $accountant = $this->memberAt(Role::ACCOUNTANT);
        $this->assertFalse($accountant->can('workflows.manage'));
        $this->actingAs($accountant);

        Livewire::test(Inbox::class)
            ->assertDontSee('Stuck approvals')
            ->call('resubmit', $instance->id)
            ->assertHasErrors('stuck');

        $this->assertSame('stalled', $instance->fresh()->status);
    }

    public function test_a_workflow_administrator_may_resubmit_anybody_is_stalled_approval(): void
    {
        $instance = $this->stalledInstance();

        $admin = $this->memberAt(Role::ADMINISTRATOR);
        $this->assertTrue($admin->can('workflows.manage'));

        $this->memberAt(Role::CASHIER);
        $this->actingAs($admin);

        Livewire::test(Inbox::class)
            ->assertSee('Stuck approvals')
            ->call('resubmit', $instance->id)
            ->assertHasNoErrors();

        $this->assertSame('running', $instance->fresh()->status);
    }

    public function test_a_finished_approval_cannot_be_resubmitted(): void
    {
        $workflow = $this->workflow([
            ['name' => 'Manager approves', 'approver_mode' => 'role', 'approver_role' => Role::MANAGER],
        ], ['is_default' => true]);
        $manager = $this->memberAt(Role::MANAGER);

        $instance = app(WorkflowEngine::class)->start($this->expense(), $workflow, $this->owner);
        app(WorkflowEngine::class)->act($instance, $manager, 'approved');

        $this->actingAs($this->owner);

        Livewire::test(Inbox::class)
            ->call('resubmit', $instance->id)
            ->assertHasErrors('stuck');

        $this->assertSame('approved', $instance->fresh()->status);
    }
}
