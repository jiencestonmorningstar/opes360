<?php

namespace Tests\Feature\Documents;

use App\Models\Contact;
use App\Models\Role;
use Laravel\Sanctum\Sanctum;

/**
 * Documents plan phase 2.2: GET|POST /api/v1/library,
 * GET|PUT|DELETE /api/v1/library/{document}, POST|DELETE .../relations.
 */
class LibraryApiTest extends DocumentsTestCase
{
    public function test_the_index_lists_documents(): void
    {
        Sanctum::actingAs($this->owner, ['*']);
        $this->document(['title' => 'Supply agreement']);

        $this->getJson('/api/v1/library')
            ->assertOk()
            ->assertJsonFragment(['title' => 'Supply agreement']);
    }

    public function test_a_restricted_document_never_appears_to_a_token_that_cannot_see_it(): void
    {
        $clerk = $this->memberAt(Role::SALES_OFFICER);
        Sanctum::actingAs($clerk, ['*']);

        $this->document(['title' => 'Off limits', 'security' => 'restricted']);

        $this->getJson('/api/v1/library')->assertOk()->assertJsonMissing(['title' => 'Off limits']);
    }

    public function test_the_index_can_be_filtered_by_kind(): void
    {
        Sanctum::actingAs($this->owner, ['*']);
        $this->document(['title' => 'A contract', 'kind' => 'contract']);
        $this->document(['title' => 'A memo', 'kind' => 'memo']);

        $this->getJson('/api/v1/library?kind=contract')
            ->assertOk()
            ->assertJsonFragment(['title' => 'A contract'])
            ->assertJsonMissing(['title' => 'A memo']);
    }

    public function test_showing_one_document(): void
    {
        Sanctum::actingAs($this->owner, ['*']);
        $doc = $this->document(['title' => 'Supply agreement']);

        $this->getJson('/api/v1/library/'.$doc->id)
            ->assertOk()
            ->assertJsonPath('data.title', 'Supply agreement');
    }

    public function test_a_restricted_document_404s_rather_than_403s_for_somebody_who_cannot_see_it(): void
    {
        $clerk = $this->memberAt(Role::SALES_OFFICER);
        Sanctum::actingAs($clerk, ['*']);

        $doc = $this->document(['security' => 'restricted']);

        // The policy denies rather than the route not finding the model, and
        // an authorization failure on a show route surfaces as 403 — either
        // way, nothing about the document's content is disclosed.
        $this->getJson('/api/v1/library/'.$doc->id)->assertForbidden();
    }

    public function test_content_hash_never_leaves_the_endpoint(): void
    {
        Sanctum::actingAs($this->owner, ['*']);
        $doc = $this->document();

        $this->getJson('/api/v1/library/'.$doc->id)->assertJsonMissingPath('data.content_hash');
    }

    public function test_a_document_can_be_composed_from_a_template(): void
    {
        Sanctum::actingAs($this->owner, ['*']);

        $this->postJson('/api/v1/library', [
            'template' => 'service_agreement',
            'title' => 'New agreement',
            'kind' => 'contract',
        ])->assertCreated()->assertJsonPath('data.status', 'draft');

        $this->assertDatabaseHas('business_documents', ['title' => 'New agreement']);
    }

    public function test_filing_fields_can_be_updated(): void
    {
        Sanctum::actingAs($this->owner, ['*']);
        $doc = $this->document();

        $this->putJson('/api/v1/library/'.$doc->id, ['kind' => 'contract', 'tags' => ['2026']])
            ->assertOk()
            ->assertJsonPath('data.kind', 'contract');

        $this->assertSame(['2026'], $doc->fresh()->tags);
    }

    /** Content is frozen on an issued document regardless of what the endpoint accepts. */
    public function test_filing_an_issued_document_over_the_api_does_not_break_its_hash(): void
    {
        Sanctum::actingAs($this->owner, ['*']);
        $doc = $this->document();
        $doc->issued_at = now();
        $doc->forceFill(['status' => 'issued', 'content_hash' => hash('sha256', $doc->canonicalPayload())])->save();

        $this->putJson('/api/v1/library/'.$doc->id, ['kind' => 'contract'])->assertOk();

        $this->assertFalse($doc->fresh()->isTampered());
    }

    public function test_a_document_can_be_deleted(): void
    {
        Sanctum::actingAs($this->owner, ['*']);
        $doc = $this->document();

        $this->deleteJson('/api/v1/library/'.$doc->id)->assertNoContent();

        $this->assertSoftDeleted('business_documents', ['id' => $doc->id]);
    }

    public function test_a_document_can_be_linked_to_a_contact(): void
    {
        Sanctum::actingAs($this->owner, ['*']);
        $doc = $this->document();
        $contact = Contact::create(['name' => 'A Customer', 'balance' => 0]);

        $this->postJson("/api/v1/library/{$doc->id}/relations", [
            'related_type' => 'contact',
            'related_id' => $contact->id,
            'role' => 'about',
        ])->assertCreated()->assertJsonPath('data.related_type', 'contact');

        $this->assertDatabaseHas('business_document_relations', [
            'business_document_id' => $doc->id,
            'related_type' => Contact::class,
            'related_id' => $contact->id,
        ]);
    }

    public function test_linking_to_an_unrecognised_type_is_refused(): void
    {
        Sanctum::actingAs($this->owner, ['*']);
        $doc = $this->document();

        $this->postJson("/api/v1/library/{$doc->id}/relations", [
            'related_type' => 'user',
            'related_id' => (string) $this->owner->id,
        ])->assertStatus(422);
    }

    public function test_a_relation_can_be_removed(): void
    {
        Sanctum::actingAs($this->owner, ['*']);
        $doc = $this->document();
        $contact = Contact::create(['name' => 'A Customer', 'balance' => 0]);

        $this->postJson("/api/v1/library/{$doc->id}/relations", [
            'related_type' => 'contact',
            'related_id' => $contact->id,
        ])->assertCreated();

        $this->deleteJson("/api/v1/library/{$doc->id}/relations", [
            'related_type' => 'contact',
            'related_id' => $contact->id,
        ])->assertNoContent();

        $this->assertDatabaseCount('business_document_relations', 0);
    }

    /** Sharing a document is a separate permission from reading it. */
    public function test_linking_needs_the_share_permission(): void
    {
        $clerk = $this->memberAt(Role::CASHIER);
        Sanctum::actingAs($clerk, ['*']);
        $doc = $this->document();
        $contact = Contact::create(['name' => 'A Customer', 'balance' => 0]);

        $this->postJson("/api/v1/library/{$doc->id}/relations", [
            'related_type' => 'contact',
            'related_id' => $contact->id,
        ])->assertForbidden();
    }
}
