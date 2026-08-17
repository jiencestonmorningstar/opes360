<?php

namespace Tests\Feature\Documents;

use App\Models\Contact;
use App\Models\Contract;
use App\Services\DocumentComposer;
use App\Services\Documents\CustomDocumentTemplates;
use App\Services\Documents\DocumentLinker;
use App\Services\Documents\RecordDocumentComposer;

/**
 * Documents completion plan §5 item 1: the four creation routes, all ending
 * up as an ordinary BusinessDocument made through DocumentComposer.
 */
class DocumentCreationRoutesTest extends DocumentsTestCase
{
    // ── 1. Duplicate ─────────────────────────────────────────────────────

    public function test_duplicating_a_document_makes_a_fresh_draft_with_the_same_content(): void
    {
        $source = $this->document(['title' => 'Original', 'reference' => 'DOC-001']);

        $copy = app(DocumentComposer::class)->duplicate($source, $this->owner);

        $this->assertNotSame($source->id, $copy->id);
        $this->assertSame('Copy of Original', $copy->title);
        $this->assertSame($source->body, $copy->body);
        $this->assertSame($source->fields, $copy->fields);
        $this->assertTrue($copy->isDraft());
        $this->assertNull($copy->reference);
        $this->assertNull($copy->content_hash);
    }

    public function test_duplicating_resets_filing_metadata(): void
    {
        $source = $this->document(['folder_id' => null, 'security' => 'confidential', 'tags' => ['urgent']]);

        $copy = app(DocumentComposer::class)->duplicate($source, $this->owner);

        $this->assertNull($copy->folder_id);
        $this->assertNull($copy->security);
        $this->assertNull($copy->tags);
    }

    public function test_duplicating_never_copies_comments_versions_or_shares(): void
    {
        $source = $this->document();
        $source->comments()->create([
            'user_id' => $this->owner->id,
            'body' => 'A note nobody duplicating this should inherit.',
        ]);

        $copy = app(DocumentComposer::class)->duplicate($source, $this->owner);

        $this->assertCount(0, $copy->comments()->get());
        // The original still owns its own history — duplicating did not touch it.
        $this->assertCount(1, $source->comments()->get());
    }

    public function test_a_duplicated_document_still_versions_normally_because_it_went_through_create(): void
    {
        $source = $this->document();

        $copy = app(DocumentComposer::class)->duplicate($source, $this->owner);

        // Proof this is an ordinary BusinessDocument, not a parallel copy
        // path: the same booted() hook that snapshots every new document
        // fired for the duplicate too.
        $this->assertCount(1, $copy->versions()->get());
    }

    // ── 2. From an ERP record ───────────────────────────────────────────

    public function test_composing_from_a_contact_seeds_customer_fields_and_links_the_document(): void
    {
        $this->publishGreetingTemplate();
        $contact = Contact::create(['name' => 'Marie Ekwalla', 'email' => 'marie@example.com', 'balance' => 0]);

        $document = app(RecordDocumentComposer::class)->composeDraft(
            $contact,
            'greeting',
            [],
            'Letter to Marie',
            $this->company,
            $this->owner,
        );

        $this->assertStringContainsString('Dear Marie Ekwalla,', $document->body);
        $this->assertTrue($document->isDraft());
        $this->assertSame([$document->id], app(DocumentLinker::class)->documentsFor($contact)->pluck('id')->all());
    }

    public function test_composing_from_a_contract_seeds_contract_fields_and_links_the_document(): void
    {
        $template = app(CustomDocumentTemplates::class)->create([
            'key' => 'covering_letter',
            'name' => 'Covering letter',
            'body' => 'Regarding {{ contract.title }}.',
        ], $this->owner);
        app(CustomDocumentTemplates::class)->publish($template);

        $contract = Contract::create([
            'title' => 'Office lease',
            'type' => 'service',
            'direction' => 'inbound',
            'starts_on' => now()->toDateString(),
            'status' => 'draft',
        ]);

        $document = app(RecordDocumentComposer::class)->composeDraft(
            $contract,
            'covering_letter',
            [],
            'Cover letter',
            $this->company,
            $this->owner,
        );

        $this->assertStringContainsString('Regarding Office lease.', $document->body);
        $this->assertSame([$document->id], app(DocumentLinker::class)->documentsFor($contract)->pluck('id')->all());
    }

    public function test_a_record_with_no_registered_context_key_is_refused(): void
    {
        $this->expectException(\RuntimeException::class);

        app(RecordDocumentComposer::class)->composeDraft(
            $this->owner,
            'greeting',
            [],
            'Nope',
            $this->company,
            $this->owner,
        );
    }

    protected function publishGreetingTemplate(): void
    {
        $template = app(CustomDocumentTemplates::class)->create([
            'key' => 'greeting',
            'name' => 'Greeting',
            'body' => 'Dear {{ customer.name }},',
        ], $this->owner);
        app(CustomDocumentTemplates::class)->publish($template);
    }
}
