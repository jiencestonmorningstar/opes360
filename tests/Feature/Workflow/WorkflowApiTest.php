<?php

namespace Tests\Feature\Workflow;

use App\Models\Role;
use App\Services\Workflow\WorkflowEngine;
use Laravel\Sanctum\Sanctum;

class WorkflowApiTest extends WorkflowTestCase
{
    public function test_the_index_lists_running_approvals(): void
    {
        $this->memberAt(Role::MANAGER);
        Sanctum::actingAs($this->owner, ['*']);

        app(WorkflowEngine::class)->start($this->expense(), $this->workflow([[]]), $this->owner);

        $this->getJson('/api/v1/approvals')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'running')
            ->assertJsonPath('data.0.workflow', 'Expense approval');
    }

    public function test_the_index_can_be_filtered_by_status(): void
    {
        $manager = $this->memberAt(Role::MANAGER);
        Sanctum::actingAs($this->owner, ['*']);

        $instance = app(WorkflowEngine::class)
            ->start($this->expense(), $this->workflow([[]]), $this->owner);
        app(WorkflowEngine::class)->act($instance, $manager, 'approved');

        $this->getJson('/api/v1/approvals?status=running')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/approvals?status=approved')->assertOk()->assertJsonCount(1, 'data');
    }

    /** Renaming a workflow must not rewrite what the API reports about the past. */
    public function test_showing_an_approval_returns_the_step_names_as_they_were(): void
    {
        $manager = $this->memberAt(Role::MANAGER);
        Sanctum::actingAs($this->owner, ['*']);

        $workflow = $this->workflow([['name' => 'Manager review']]);
        $instance = app(WorkflowEngine::class)->start($this->expense(), $workflow, $this->owner);
        app(WorkflowEngine::class)->act($instance, $manager, 'approved');

        $workflow->steps->first()->update(['name' => 'Something else entirely']);

        $this->getJson('/api/v1/approvals/'.$instance->id)
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonFragment(['step' => 'Manager review'])
            ->assertJsonMissing(['step' => 'Something else entirely']);
    }

    public function test_mine_lists_what_is_waiting_on_the_tokens_user(): void
    {
        $manager = $this->memberAt(Role::MANAGER);
        Sanctum::actingAs($manager, ['*']);

        app(WorkflowEngine::class)->start($this->expense(), $this->workflow([[]]), $this->owner);

        $this->getJson('/api/v1/approvals/mine')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.step', 'Step 1');
    }

    public function test_mine_is_empty_for_somebody_who_was_not_asked(): void
    {
        $this->memberAt(Role::MANAGER);
        $bystander = $this->memberAt(Role::CASHIER);
        Sanctum::actingAs($bystander, ['*']);

        app(WorkflowEngine::class)->start($this->expense(), $this->workflow([[]]), $this->owner);

        $this->getJson('/api/v1/approvals/mine')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_decision_can_be_recorded_over_the_api(): void
    {
        $manager = $this->memberAt(Role::MANAGER);
        Sanctum::actingAs($manager, ['*']);

        $instance = app(WorkflowEngine::class)
            ->start($this->expense(), $this->workflow([[]]), $this->owner);

        $this->postJson('/api/v1/approvals/'.$instance->id.'/decisions', [
            'action' => 'approved',
            'comment' => 'Within budget.',
        ])->assertOk()->assertJsonPath('data.status', 'approved');

        $this->assertSame('approved', $instance->fresh()->status);
    }

    /** Being asked is the permission — and not being asked is the refusal. */
    public function test_somebody_who_was_not_asked_is_refused(): void
    {
        $this->memberAt(Role::MANAGER);
        $bystander = $this->memberAt(Role::CASHIER);
        Sanctum::actingAs($bystander, ['*']);

        $instance = app(WorkflowEngine::class)
            ->start($this->expense(), $this->workflow([[]]), $this->owner);

        $this->postJson('/api/v1/approvals/'.$instance->id.'/decisions', ['action' => 'approved'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'not_assigned');

        $this->assertSame('running', $instance->fresh()->status);
    }

    public function test_an_unknown_action_is_rejected(): void
    {
        $manager = $this->memberAt(Role::MANAGER);
        Sanctum::actingAs($manager, ['*']);

        $instance = app(WorkflowEngine::class)
            ->start($this->expense(), $this->workflow([[]]), $this->owner);

        $this->postJson('/api/v1/approvals/'.$instance->id.'/decisions', ['action' => 'delegated'])
            ->assertStatus(422);
    }

    /** Reading the rules is one permission; a cashier has neither. */
    public function test_listing_approvals_needs_the_view_permission(): void
    {
        Sanctum::actingAs($this->memberAt(Role::CASHIER), ['*']);

        $this->getJson('/api/v1/approvals')->assertForbidden();
    }
}
