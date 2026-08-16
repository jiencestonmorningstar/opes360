<?php

namespace Tests\Feature\Documents;

class DocumentLockingTest extends DocumentsTestCase
{
    public function test_a_locked_draft_cannot_be_edited(): void
    {
        $paper = $this->document(['is_locked' => true]);

        $this->assertFalse($this->owner->can('update', $paper));
    }

    /** Locking is filing, not content, and must not flood the version history. */
    public function test_locking_is_itself_a_filing_action(): void
    {
        $paper = $this->document();

        $this->assertTrue($this->owner->can('file', $paper));

        $paper->update(['is_locked' => true]);

        $this->assertSame(1, $paper->fresh()->versions()->count());
    }

    public function test_an_unlocked_draft_can_be_edited_again(): void
    {
        $paper = $this->document(['is_locked' => true]);

        $paper->update(['is_locked' => false]);

        $this->assertTrue($this->owner->can('update', $paper->fresh()));
    }

    public function test_locking_does_not_require_issuing(): void
    {
        $paper = $this->document(['is_locked' => true]);

        $this->assertTrue($paper->fresh()->isDraft());
        $this->assertFalse($paper->fresh()->isIssued());
    }

    public function test_a_locked_document_can_still_be_issued(): void
    {
        $paper = $this->document(['is_locked' => true]);

        $this->assertTrue($this->owner->can('issue', $paper));
    }
}
