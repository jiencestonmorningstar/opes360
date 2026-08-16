<?php

namespace Tests\Feature\Documents;

use App\Services\Documents\DocumentVersioner;
use RuntimeException;

class DocumentVersionTest extends DocumentsTestCase
{
    public function test_creating_a_document_writes_its_first_version(): void
    {
        $paper = $this->document(['title' => 'Service agreement']);

        $this->assertSame(1, $paper->versions()->count());
        $this->assertSame('Service agreement', $paper->versions()->first()->title);
    }

    public function test_editing_content_writes_a_new_version(): void
    {
        $paper = $this->document(['title' => 'Draft title']);

        $paper->update(['title' => 'Revised title']);

        $this->assertSame(2, $paper->fresh()->versions()->count());
        $this->assertSame(
            'Revised title',
            $paper->fresh()->versions()->orderBy('version_number', 'desc')->first()->title,
        );
    }

    /** Filing is not editing, and must not flood the history with nothing to compare. */
    public function test_filing_a_document_does_not_write_a_new_version(): void
    {
        $paper = $this->document();

        $paper->update(['tags' => ['2026'], 'folder_id' => null]);

        $this->assertSame(1, $paper->fresh()->versions()->count());
    }

    public function test_versions_are_numbered_in_order(): void
    {
        $paper = $this->document(['title' => 'One']);
        $paper->update(['title' => 'Two']);
        $paper->update(['title' => 'Three']);

        $this->assertSame(
            [1, 2, 3],
            $paper->fresh()->versions()->orderBy('version_number')->pluck('version_number')->all(),
        );
    }

    public function test_a_version_records_who_made_it(): void
    {
        $this->actingAs($this->owner);
        $paper = $this->document();

        $this->assertSame($this->owner->id, $paper->versions()->first()->created_by);
    }

    public function test_restoring_a_version_updates_the_current_content(): void
    {
        $paper = $this->document(['title' => 'Original', 'body' => 'Original terms.']);
        $paper->update(['title' => 'Changed', 'body' => 'Changed terms.']);
        $original = $paper->fresh()->versions()->orderBy('version_number')->first();

        app(DocumentVersioner::class)->restore($paper->fresh(), $original, $this->owner);

        $this->assertSame('Original', $paper->fresh()->title);
        $this->assertSame('Original terms.', $paper->fresh()->body);
    }

    /** Restoring is itself a versioned edit, or the two records of "now" disagree. */
    public function test_restoring_creates_a_new_version_rather_than_rewriting_history(): void
    {
        $paper = $this->document(['title' => 'Original']);
        $paper->update(['title' => 'Changed']);
        $original = $paper->fresh()->versions()->orderBy('version_number')->first();

        app(DocumentVersioner::class)->restore($paper->fresh(), $original, $this->owner);

        $versions = $paper->fresh()->versions()->orderBy('version_number')->get();

        $this->assertSame(3, $versions->count());
        $this->assertSame('Original', $versions[0]->title);
        $this->assertSame('Changed', $versions[1]->title);
        $this->assertSame('Original', $versions[2]->title);
    }

    public function test_an_issued_document_cannot_be_restored(): void
    {
        $paper = $this->document();
        $paper->issued_at = now();
        $paper->forceFill(['status' => 'issued', 'content_hash' => hash('sha256', $paper->canonicalPayload())])->save();

        $version = $paper->versions()->first();

        $this->expectException(RuntimeException::class);

        app(DocumentVersioner::class)->restore($paper->fresh(), $version, $this->owner);
    }

    public function test_deleting_a_document_takes_its_versions(): void
    {
        $paper = $this->document();

        $paper->forceDelete();

        $this->assertDatabaseCount('business_document_versions', 0);
    }

    public function test_a_version_cannot_be_edited_directly(): void
    {
        $paper = $this->document();
        $version = $paper->versions()->first();

        $this->expectException(RuntimeException::class);

        $version->update(['title' => 'Tampered']);
    }
}
