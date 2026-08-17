<?php

namespace Tests\Feature\Api;

use App\Models\ExpenseClaim;
use App\Models\PurchaseRequisition;
use App\Models\Workflow;

/**
 * Approval-path definitions over the token API — the same correctness rules
 * the admin screens enforce, held over HTTP.
 */
class WorkflowRuleApiTest extends Wave4ApiTestCase
{
    public function test_a_workflow_is_created_inactive_with_its_steps_in_order(): void
    {
        $this->postJson('/api/v1/workflows', [
            'name' => 'Purchase requests',
            'subject_type' => PurchaseRequisition::class,
            'steps' => [
                ['name' => 'Manager approves', 'approver_mode' => 'manager'],
                ['name' => 'Owner signs', 'approver_mode' => 'owner'],
            ],
        ])->assertCreated()
            // Live before its steps say something would wave everything through.
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.is_default', false)
            ->assertJsonPath('data.steps.0.position', 1)
            ->assertJsonPath('data.steps.1.position', 2)
            ->assertJsonPath('data.steps.1.name', 'Owner signs');
    }

    public function test_an_empty_workflow_cannot_be_activated(): void
    {
        $id = $this->postJson('/api/v1/workflows', [
            'name' => 'Empty path',
            'subject_type' => ExpenseClaim::class,
        ])->json('data.id');

        $this->patchJson("/api/v1/workflows/{$id}", ['is_active' => true])
            ->assertStatus(422)
            ->assertJsonStructure(['message']);
    }

    public function test_steps_can_be_replaced_and_the_path_activated(): void
    {
        $id = $this->postJson('/api/v1/workflows', [
            'name' => 'Claims',
            'subject_type' => ExpenseClaim::class,
            'steps' => [['name' => 'Owner approves', 'approver_mode' => 'owner']],
        ])->json('data.id');

        $this->patchJson("/api/v1/workflows/{$id}", [
            'is_active' => true,
            'steps' => [
                ['name' => 'Manager first', 'approver_mode' => 'manager'],
                ['name' => 'Owner approves', 'approver_mode' => 'owner', 'due_days' => 3],
            ],
        ])->assertOk()
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.steps.0.name', 'Manager first')
            ->assertJsonPath('data.steps.1.due_days', 3);
    }

    public function test_only_the_named_approver_field_is_stored(): void
    {
        // A role step must not keep a stale user id beside it — the stored
        // rule would disagree with the one that was sent.
        $this->postJson('/api/v1/workflows', [
            'name' => 'Role path',
            'subject_type' => PurchaseRequisition::class,
            'steps' => [[
                'name' => 'Accountant signs',
                'approver_mode' => 'role',
                'approver_role' => 'accountant',
                'approver_user_id' => 999,
            ]],
        ])->assertCreated()
            ->assertJsonPath('data.steps.0.approver_role', 'accountant')
            ->assertJsonPath('data.steps.0.approver_user_id', null);
    }

    public function test_frozen_copies_are_not_definitions(): void
    {
        $id = $this->postJson('/api/v1/workflows', [
            'name' => 'Original',
            'subject_type' => ExpenseClaim::class,
            'steps' => [['name' => 'Owner approves', 'approver_mode' => 'owner']],
        ])->json('data.id');

        $archive = Workflow::create([
            'name' => 'Original',
            'subject_type' => ExpenseClaim::class,
            'archived_from_id' => $id,
            'is_active' => false,
            'is_default' => false,
        ]);

        // The copy exists only to finish what started under it.
        $this->getJson('/api/v1/workflows')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/workflows/{$archive->id}")->assertNotFound();
        $this->patchJson("/api/v1/workflows/{$archive->id}", ['name' => 'Rewritten'])->assertNotFound();
    }
}
