<?php

namespace Tests\Feature\Workflow;

use App\Livewire\Workflow\Inbox;
use App\Models\Role;
use App\Models\Workflow;
use App\Services\Workflow\WorkflowEngine;
use App\Support\Permissions;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use RuntimeException;

class WorkflowInboxTest extends WorkflowTestCase
{
    // ── Permissions ───────────────────────────────────────────────────────

    public function test_the_catalogue_carries_the_workflow_abilities(): void
    {
        $this->assertSame(['view', 'manage'], Permissions::CATALOGUE['Workflows']);
        $this->assertTrue(Gate::has('workflows.view'));
        $this->assertTrue(Gate::has('workflows.manage'));
    }

    /**
     * Whoever can edit a workflow can write themselves a path with no
     * approver in it, which is the same thing as being able to spend the
     * money. So it stops at the Owner and the Administrator.
     */
    public function test_only_the_owner_and_administrator_may_define_a_workflow(): void
    {
        $this->assertTrue($this->memberAt(Role::ADMINISTRATOR)->can('create', Workflow::class));
        $this->assertFalse($this->memberAt(Role::MANAGER)->can('create', Workflow::class));
        $this->assertFalse($this->memberAt(Role::ACCOUNTANT)->can('create', Workflow::class));
    }

    public function test_a_manager_can_still_see_what_the_rules_are(): void
    {
        $this->assertTrue($this->memberAt(Role::MANAGER)->can('viewAny', Workflow::class));
    }

    /** Being asked IS the permission — a second gate would lock out the approver. */
    public function test_approving_does_not_require_a_workflow_permission(): void
    {
        $manager = $this->memberAt(Role::MANAGER);

        $instance = app(WorkflowEngine::class)
            ->start($this->expense(), $this->workflow([[]]), $this->owner);

        $this->assertFalse($manager->can('create', Workflow::class));

        app(WorkflowEngine::class)->act($instance, $manager, 'approved');

        $this->assertSame('approved', $instance->fresh()->status);
    }

    // ── The inbox ─────────────────────────────────────────────────────────

    public function test_the_inbox_lists_what_is_waiting_on_you(): void
    {
        $manager = $this->memberAt(Role::MANAGER);
        $this->actingAs($manager);

        app(WorkflowEngine::class)->start($this->expense(), $this->workflow([[]]), $this->owner);

        Livewire::test(Inbox::class)->assertSee('Generator fuel');
    }

    public function test_it_does_not_list_what_is_waiting_on_somebody_else(): void
    {
        $this->memberAt(Role::MANAGER);
        $bystander = $this->memberAt(Role::CASHIER);
        $this->actingAs($bystander);

        app(WorkflowEngine::class)->start($this->expense(), $this->workflow([[]]), $this->owner);

        Livewire::test(Inbox::class)->assertDontSee('Generator fuel');
    }

    public function test_approving_from_the_inbox_advances_the_workflow(): void
    {
        $manager = $this->memberAt(Role::MANAGER);
        $this->actingAs($manager);

        $instance = app(WorkflowEngine::class)
            ->start($this->expense(), $this->workflow([[]]), $this->owner);

        Livewire::test(Inbox::class)->call('approve', $instance->id);

        $this->assertSame('approved', $instance->fresh()->status);
    }

    public function test_rejecting_from_the_inbox_stops_the_workflow(): void
    {
        $manager = $this->memberAt(Role::MANAGER);
        $this->actingAs($manager);

        $instance = app(WorkflowEngine::class)
            ->start($this->expense(), $this->workflow([[]]), $this->owner);

        Livewire::test(Inbox::class)->call('reject', $instance->id);

        $this->assertSame('rejected', $instance->fresh()->status);
    }

    public function test_asking_for_changes_from_the_inbox_returns_it(): void
    {
        $manager = $this->memberAt(Role::MANAGER);
        $this->actingAs($manager);

        $instance = app(WorkflowEngine::class)
            ->start($this->expense(), $this->workflow([[]]), $this->owner);

        Livewire::test(Inbox::class)
            ->set('comment', 'Attach the receipt.')
            ->call('requestChanges', $instance->id);

        $this->assertSame('changes_requested', $instance->fresh()->status);
        $this->assertSame(
            'Attach the receipt.',
            $instance->fresh()->decisions()->where('action', 'changes_requested')->first()->comment,
        );
    }

    public function test_an_item_leaves_the_inbox_once_it_is_dealt_with(): void
    {
        $manager = $this->memberAt(Role::MANAGER);
        $this->actingAs($manager);

        $instance = app(WorkflowEngine::class)
            ->start($this->expense(), $this->workflow([[]]), $this->owner);

        app(WorkflowEngine::class)->act($instance, $manager, 'approved');

        Livewire::test(Inbox::class)->assertDontSee('Generator fuel');
    }

    /**
     * A guessed or stale instance id from the page must not reach somebody
     * else's assignment.
     */
    public function test_acting_on_an_instance_you_were_not_asked_about_is_refused(): void
    {
        $this->memberAt(Role::MANAGER);
        $bystander = $this->memberAt(Role::CASHIER);
        $this->actingAs($bystander);

        $instance = app(WorkflowEngine::class)
            ->start($this->expense(), $this->workflow([[]]), $this->owner);

        $this->expectException(RuntimeException::class);

        Livewire::test(Inbox::class)->call('approve', $instance->id);
    }

    public function test_an_overdue_item_is_marked(): void
    {
        $manager = $this->memberAt(Role::MANAGER);
        $this->actingAs($manager);

        app(WorkflowEngine::class)->start(
            $this->expense(),
            $this->workflow([['due_days' => 3]]),
            $this->owner,
        );

        $this->travel(5)->days();

        Livewire::test(Inbox::class)->assertSee('Overdue');
    }

    public function test_the_route_opens_for_anybody_signed_in(): void
    {
        $this->actingAs($this->memberAt(Role::CASHIER));

        $this->get(route('actions'))->assertOk();
    }
}
