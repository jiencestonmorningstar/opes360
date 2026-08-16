<?php

namespace Tests\Feature\Documents;

use App\Services\Documents\DocumentActivity;
use App\Services\Documents\DocumentComments;

class DocumentActivityTest extends DocumentsTestCase
{
    public function test_creating_a_document_appears_in_its_timeline(): void
    {
        $this->actingAs($this->owner);
        $paper = $this->document(['title' => 'Service agreement']);

        $timeline = $this->activity()->timeline($paper);

        $this->assertTrue($timeline->contains(fn ($e) => $e['type'] === 'created'));
    }

    public function test_a_content_change_appears_as_both_a_log_entry_and_a_version(): void
    {
        $this->actingAs($this->owner);
        $paper = $this->document(['title' => 'One']);
        $paper->update(['title' => 'Two']);

        $timeline = $this->activity()->timeline($paper->fresh());

        $this->assertTrue($timeline->contains(fn ($e) => $e['type'] === 'version_created'));
        $this->assertTrue($timeline->contains(fn ($e) => str_starts_with($e['summary'], 'Updated')));
    }

    public function test_issuing_is_summarised_plainly_rather_than_as_a_field_list(): void
    {
        $this->actingAs($this->owner);
        $paper = $this->document();
        $paper->issued_at = now();
        $paper->forceFill(['status' => 'issued', 'content_hash' => hash('sha256', $paper->canonicalPayload())])->save();

        $timeline = $this->activity()->timeline($paper->fresh());

        $this->assertTrue($timeline->contains(fn ($e) => $e['summary'] === 'Document issued'));
    }

    public function test_a_comment_appears_in_the_timeline(): void
    {
        $this->actingAs($this->owner);
        $paper = $this->document();
        app(DocumentComments::class)->post($paper, $this->owner, 'A remark.');

        $timeline = $this->activity()->timeline($paper->fresh());

        $this->assertTrue($timeline->contains(fn ($e) => $e['type'] === 'commented'));
    }

    public function test_a_reply_is_distinguished_from_a_top_level_comment(): void
    {
        $this->actingAs($this->owner);
        $paper = $this->document();
        $comments = app(DocumentComments::class);
        $original = $comments->post($paper, $this->owner, 'Question.');
        $comments->post($paper, $this->owner, 'Answer.', $original);

        $timeline = $this->activity()->timeline($paper->fresh());

        $this->assertTrue($timeline->contains(fn ($e) => $e['type'] === 'replied'));
        $this->assertTrue($timeline->contains(fn ($e) => $e['type'] === 'commented'));
    }

    public function test_the_timeline_is_ordered_newest_first(): void
    {
        $this->actingAs($this->owner);
        $paper = $this->document();
        app(DocumentComments::class)->post($paper, $this->owner, 'First.');
        $this->travel(1)->minutes();
        $paper->update(['title' => 'Renamed']);

        $timeline = $this->activity()->timeline($paper->fresh());
        $ats = $timeline->pluck('at')->map(fn ($t) => $t->timestamp)->all();

        $sorted = $ats;
        rsort($sorted);
        $this->assertSame($sorted, $ats);
    }

    /** Content_hash is redacted at the audit layer already — confirm the timeline never surfaces it either. */
    public function test_the_timeline_never_carries_the_tamper_hash(): void
    {
        $this->actingAs($this->owner);
        $paper = $this->document();
        $paper->issued_at = now();
        $paper->forceFill(['status' => 'issued', 'content_hash' => hash('sha256', $paper->canonicalPayload())])->save();

        $timeline = $this->activity()->timeline($paper->fresh());

        foreach ($timeline as $entry) {
            $this->assertStringNotContainsString('content_hash', json_encode($entry));
        }
    }

    protected function activity(): DocumentActivity
    {
        return app(DocumentActivity::class);
    }
}
