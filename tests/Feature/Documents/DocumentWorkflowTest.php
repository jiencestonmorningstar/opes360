<?php

namespace Tests\Feature\Documents;

use App\Listeners\TranslateDocumentWorkflowEvents;
use App\Models\AutomationRule;
use App\Models\BusinessDocument;
use App\Models\Expense;
use App\Models\Role;
use App\Models\Workflow;
use App\Services\Workflow\WorkflowEngine;

/**
 * Documents plan phase 2.7: Documents consumes the workflow engine rather
 * than growing its own approval mechanism — the exact duplication the
 * master brief forbids.
 */
class DocumentWorkflowTest extends DocumentsTestCase
{
    public function test_a_document_can_be_submitted_for_approval(): void
    {
        $manager = $this->memberAt(Role::MANAGER);
        $paper = $this->document();

        $instance = app(WorkflowEngine::class)->start($paper, $this->workflow(), $this->owner);

        $this->assertTrue($paper->fresh()->isAwaitingApproval());
        $this->assertSame([$manager->id], $instance->assignments->pluck('user_id')->all());
    }

    public function test_an_approved_document_is_reported_as_approved(): void
    {
        $manager = $this->memberAt(Role::MANAGER);
        $paper = $this->document();
        $instance = app(WorkflowEngine::class)->start($paper, $this->workflow(), $this->owner);

        app(WorkflowEngine::class)->act($instance, $manager, 'approved');

        $this->assertTrue($paper->fresh()->isApproved());
        $this->assertFalse($paper->fresh()->isAwaitingApproval());
    }

    /**
     * Documents-specific automation must be able to match "document.approved"
     * — the generic workflow.approved does not distinguish a document from an
     * expense or a project sharing the same engine. Proven end to end, with a
     * real automation rule reacting to the translated name, rather than by
     * inspecting the event bus: Event::fake() disables every real listener,
     * including the translator itself, so faking it would prove nothing about
     * whether the translation actually happens.
     */
    public function test_starting_a_documents_approval_emits_a_document_named_event(): void
    {
        $this->rule('document.submitted', 'description', 'Submitted for review.');
        $paper = $this->document();

        app(WorkflowEngine::class)->start($paper, $this->workflow(), $this->owner);

        $this->assertSame('Submitted for review.', $paper->fresh()->description);
    }

    public function test_approving_a_document_emits_document_approved(): void
    {
        $this->rule('document.approved', 'description', 'Approved.');
        $manager = $this->memberAt(Role::MANAGER);
        $paper = $this->document();
        $instance = app(WorkflowEngine::class)->start($paper, $this->workflow(), $this->owner);

        app(WorkflowEngine::class)->act($instance, $manager, 'approved');

        $this->assertSame('Approved.', $paper->fresh()->description);
    }

    public function test_rejecting_a_document_emits_document_rejected_not_document_approved(): void
    {
        $this->rule('document.rejected', 'description', 'Rejected.');
        $this->rule('document.approved', 'description', 'Approved.');
        $manager = $this->memberAt(Role::MANAGER);
        $paper = $this->document();
        $instance = app(WorkflowEngine::class)->start($paper, $this->workflow(), $this->owner);

        app(WorkflowEngine::class)->act($instance, $manager, 'rejected', 'Missing signature block.');

        $this->assertSame('Rejected.', $paper->fresh()->description);
    }

    /**
     * Structural, not empirical: the translator only maps workflow.* names,
     * and every name it produces is document.* — which is never a key in its
     * own map. There is no path back in, so this asserts that directly rather
     * than trying to observe an infinite loop stopping.
     */
    public function test_the_translator_has_no_path_back_to_itself(): void
    {
        $translator = new TranslateDocumentWorkflowEvents;
        $reflection = new \ReflectionClass($translator);
        $map = $reflection->getConstant('MAP');

        foreach ($map as $produced) {
            $this->assertArrayNotHasKey($produced, $map, "{$produced} would re-enter the translator.");
        }
    }

    /** An expense's approval must not be mistaken for a document's. */
    public function test_a_non_document_subject_is_not_translated(): void
    {
        $this->rule('document.submitted', 'description', 'Should never apply to an expense.');
        $this->memberAt(Role::MANAGER);
        $expense = Expense::create([
            'description' => 'Fuel', 'category' => 'fuel',
            'issue_date' => now()->toDateString(), 'amount' => 1000, 'total' => 1000,
            'status' => 'draft', 'recorded_by' => $this->owner->id,
        ]);

        app(WorkflowEngine::class)->start($expense, $this->workflow(Expense::class), $this->owner);

        $this->assertSame('Fuel', $expense->fresh()->description);
    }

    protected function rule(string $event, string $field, string $value): AutomationRule
    {
        return AutomationRule::create([
            'name' => 'Test rule for '.$event,
            'event' => $event,
            'action' => 'set_field',
            'action_config' => ['field' => $field, 'value' => $value],
            'is_active' => true,
        ]);
    }

    protected function workflow(string $subjectType = BusinessDocument::class): Workflow
    {
        $workflow = Workflow::create([
            'name' => 'Document approval',
            'subject_type' => $subjectType,
            'is_active' => true,
        ]);

        $workflow->steps()->create([
            'position' => 1,
            'name' => 'Manager review',
            'type' => 'approval',
            'approver_mode' => 'role',
            'approver_role' => Role::MANAGER,
            'quorum' => 'any',
        ]);

        return $workflow->fresh();
    }
}
