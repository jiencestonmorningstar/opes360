<?php

namespace Tests\Feature\Documents;

use App\Services\Documents\VersionComparator;

class VersionComparatorTest extends DocumentsTestCase
{
    public function test_identical_versions_show_no_change(): void
    {
        $paper = $this->document(['title' => 'Service agreement']);
        $only = $paper->versions()->first();

        $result = $this->comparator()->compare($only, $only);

        $this->assertFalse($result['fields']['title']['changed']);
    }

    public function test_an_added_word_is_flagged(): void
    {
        $paper = $this->document(['body' => 'The term is one year.']);
        $paper->update(['body' => 'The term is exactly one year.']);

        $versions = $paper->fresh()->versions()->orderBy('version_number')->get();
        $result = $this->comparator()->compare($versions[0], $versions[1]);

        $this->assertTrue($result['fields']['body']['changed']);
        $added = array_filter($result['fields']['body']['diff'], fn ($t) => $t['op'] === 'added');
        $this->assertContains('exactly', array_column($added, 'text'));
    }

    public function test_a_removed_word_is_flagged(): void
    {
        $paper = $this->document(['body' => 'The term is exactly one year.']);
        $paper->update(['body' => 'The term is one year.']);

        $versions = $paper->fresh()->versions()->orderBy('version_number')->get();
        $result = $this->comparator()->compare($versions[0], $versions[1]);

        $removed = array_filter($result['fields']['body']['diff'], fn ($t) => $t['op'] === 'removed');
        $this->assertContains('exactly', array_column($removed, 'text'));
    }

    public function test_unchanged_words_are_kept_and_in_order(): void
    {
        $paper = $this->document(['body' => 'One two three.']);
        $paper->update(['body' => 'One two four three.']);

        $versions = $paper->fresh()->versions()->orderBy('version_number')->get();
        $result = $this->comparator()->compare($versions[0], $versions[1]);

        $kept = array_values(array_filter(
            $result['fields']['body']['diff'],
            fn ($t) => $t['op'] === 'kept' && trim($t['text']) !== '',
        ));
        $this->assertSame(['One', 'two', 'three.'], array_column($kept, 'text'));
    }

    public function test_a_complete_rewrite_removes_everything_and_adds_everything(): void
    {
        $paper = $this->document(['body' => 'Old wording entirely.']);
        $paper->update(['body' => 'Completely different text.']);

        $versions = $paper->fresh()->versions()->orderBy('version_number')->get();
        $result = $this->comparator()->compare($versions[0], $versions[1]);

        // Whitespace tokens may legitimately match on both sides; no actual
        // word should survive the rewrite unchanged.
        $keptWords = array_filter(
            $result['fields']['body']['diff'],
            fn ($t) => $t['op'] === 'kept' && trim($t['text']) !== '',
        );
        $this->assertEmpty($keptWords);
    }

    public function test_an_empty_field_compares_cleanly(): void
    {
        $paper = $this->document(['body' => null]);
        $paper->update(['body' => 'Now it has content.']);

        $versions = $paper->fresh()->versions()->orderBy('version_number')->get();
        $result = $this->comparator()->compare($versions[0], $versions[1]);

        $this->assertTrue($result['fields']['body']['changed']);
        $this->assertNotEmpty(array_filter($result['fields']['body']['diff'], fn ($t) => $t['op'] === 'added'));
    }

    public function test_the_result_names_which_version_is_which(): void
    {
        $paper = $this->document(['title' => 'One']);
        $paper->update(['title' => 'Two']);

        $versions = $paper->fresh()->versions()->orderBy('version_number')->get();
        $result = $this->comparator()->compare($versions[0], $versions[1]);

        $this->assertSame(1, $result['from']['version']);
        $this->assertSame(2, $result['to']['version']);
    }

    protected function comparator(): VersionComparator
    {
        return app(VersionComparator::class);
    }
}
