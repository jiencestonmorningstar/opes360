<?php

namespace Tests\Feature\Automation;

use App\Models\BusinessDocument;
use App\Models\Contract;
use App\Services\Documents\CustomDocumentTemplates;
use App\Services\Documents\DocumentLinker;

/**
 * Documents completion plan §5 item 4: an automation rule that drafts a
 * document from a template when its event fires.
 */
class ComposeDocumentActionTest extends AutomationTestCase
{
    public function test_a_rule_can_draft_a_document_from_the_triggering_record(): void
    {
        $this->publishCoveringLetterTemplate();
        $contract = Contract::create(['title' => 'Vehicle lease', 'type' => 'service', 'starts_on' => now()->toDateString()]);

        $this->rule([
            'event' => 'contract.signed',
            'action' => 'compose_document',
            'action_config' => ['template' => 'covering_letter', 'title' => 'Covering letter'],
        ]);

        $contract->emitDomainEvent('contract.signed');

        $documents = app(DocumentLinker::class)->documentsFor($contract);
        $this->assertCount(1, $documents);
        $this->assertStringContainsString('Regarding Vehicle lease.', $documents->first()->body);
        $this->assertSame('Covering letter', $documents->first()->title);
        $this->assertTrue($documents->first()->isDraft());
    }

    /** No registered field context for the subject: composes with company-only context rather than failing. */
    public function test_a_rule_composes_even_when_the_subject_has_no_registered_field_context(): void
    {
        app(CustomDocumentTemplates::class)->publish(
            app(CustomDocumentTemplates::class)->create([
                'key' => 'plain_notice',
                'name' => 'Plain notice',
                'body' => 'Dear {{ company.name }},',
            ], $this->owner)
        );

        $this->rule([
            'event' => 'expense.recorded',
            'action' => 'compose_document',
            'action_config' => ['template' => 'plain_notice'],
        ]);

        $expense = $this->expense();
        $expense->emitDomainEvent('expense.recorded');

        $documents = app(DocumentLinker::class)->documentsFor($expense);
        $this->assertCount(1, $documents);
        $this->assertStringContainsString('Dear Acme Sarl,', $documents->first()->body);
    }

    public function test_composing_without_a_template_key_never_leaves_a_partial_document(): void
    {
        $this->rule([
            'event' => 'expense.recorded',
            'action' => 'compose_document',
            'action_config' => [],
        ]);

        $this->expense()->emitDomainEvent('expense.recorded');

        // The bad rule's own throw is swallowed by the emitter (proven
        // generically in AutomationTest); this asserts the specific
        // consequence: no half-made document exists.
        $this->assertSame(0, BusinessDocument::count());
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
}
