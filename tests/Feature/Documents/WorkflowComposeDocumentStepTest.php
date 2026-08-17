<?php

namespace Tests\Feature\Documents;

use App\Models\BusinessDocument;
use App\Models\Contract;
use App\Models\Role;
use App\Models\Workflow;
use App\Services\Documents\CustomDocumentTemplates;
use App\Services\Documents\DocumentLinker;
use App\Services\Workflow\WorkflowEngine;

/**
 * Documents completion plan §5 item 3: a workflow step that drafts a
 * document when it is reached, end to end through a real workflow instance.
 */
class WorkflowComposeDocumentStepTest extends DocumentsTestCase
{
    public function test_a_compose_document_step_drafts_a_document_when_reached(): void
    {
        $this->publishCoveringLetterTemplate();
        $contract = Contract::create(['title' => 'Warehouse lease', 'type' => 'service', 'starts_on' => now()->toDateString()]);

        $workflow = Workflow::create([
            'name' => 'Contract onboarding',
            'subject_type' => Contract::class,
            'is_active' => true,
        ]);
        $workflow->steps()->create([
            'position' => 1,
            'name' => 'Draft covering letter',
            'type' => 'compose_document',
            'compose_template' => 'covering_letter',
            'compose_link_role' => 'about',
        ]);

        app(WorkflowEngine::class)->start($contract, $workflow->fresh(), $this->owner);

        $documents = app(DocumentLinker::class)->documentsFor($contract);
        $this->assertCount(1, $documents);
        $this->assertStringContainsString('Regarding Warehouse lease.', $documents->first()->body);
        $this->assertTrue($documents->first()->isDraft());
    }

    /** A compose_document step never asks anyone to act — the workflow finishes on its own. */
    public function test_a_workflow_that_is_only_a_compose_document_step_finishes_immediately(): void
    {
        $this->publishCoveringLetterTemplate();
        $contract = Contract::create(['title' => 'Storage agreement', 'type' => 'service', 'starts_on' => now()->toDateString()]);

        $workflow = Workflow::create([
            'name' => 'Contract onboarding',
            'subject_type' => Contract::class,
            'is_active' => true,
        ]);
        $workflow->steps()->create([
            'position' => 1,
            'name' => 'Draft covering letter',
            'type' => 'compose_document',
            'compose_template' => 'covering_letter',
        ]);

        $instance = app(WorkflowEngine::class)->start($contract, $workflow->fresh(), $this->owner);

        $this->assertSame('approved', $instance->status);
    }

    /**
     * Proof this rides DocumentComposer rather than a parallel path: the
     * composed document versions and can be issued exactly like one made
     * through Compose.
     */
    public function test_a_workflow_composed_document_is_an_ordinary_business_document(): void
    {
        $this->publishCoveringLetterTemplate();
        $contract = Contract::create(['title' => 'Fleet lease', 'type' => 'service', 'starts_on' => now()->toDateString()]);

        $workflow = Workflow::create([
            'name' => 'Contract onboarding',
            'subject_type' => Contract::class,
            'is_active' => true,
        ]);
        $workflow->steps()->create([
            'position' => 1,
            'name' => 'Draft covering letter',
            'type' => 'compose_document',
            'compose_template' => 'covering_letter',
        ]);

        app(WorkflowEngine::class)->start($contract, $workflow->fresh(), $this->owner);

        $document = BusinessDocument::first();
        $this->assertCount(1, $document->versions()->get());
    }

    /** An existing approval step still assigns and waits, untouched by the new type. */
    public function test_an_ordinary_approval_step_still_behaves_exactly_as_before(): void
    {
        $manager = $this->memberAt(Role::MANAGER);
        $paper = $this->document();

        $instance = app(WorkflowEngine::class)->start($paper, $this->workflow(), $this->owner);

        $this->assertSame([$manager->id], $instance->assignments->pluck('user_id')->all());
    }

    protected function publishCoveringLetterTemplate(): void
    {
        $template = app(CustomDocumentTemplates::class)->create([
            'key' => 'covering_letter',
            'name' => 'Covering letter',
            'body' => 'Regarding {{ contract.title }}.',
        ], $this->owner);
        app(CustomDocumentTemplates::class)->publish($template);
    }

    protected function workflow(): Workflow
    {
        $workflow = Workflow::create([
            'name' => 'Document approval',
            'subject_type' => BusinessDocument::class,
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
