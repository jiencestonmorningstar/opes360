<?php

namespace Tests\Feature\Documents;

use App\Services\Documents\CustomDocumentTemplates;
use App\Services\DocumentComposer;
use RuntimeException;

class CustomTemplateTest extends DocumentsTestCase
{
    public function test_a_template_can_be_created(): void
    {
        $template = $this->templates()->create($this->payload(), $this->owner);

        $this->assertSame('Board Resolution', $template->name);
        $this->assertFalse($template->is_published);
    }

    public function test_creating_writes_the_first_version(): void
    {
        $template = $this->templates()->create($this->payload(), $this->owner);

        $this->assertSame(1, $template->versions()->count());
    }

    public function test_a_key_already_used_by_a_built_in_template_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        $this->templates()->create($this->payload(['key' => 'service_agreement']), $this->owner);
    }

    public function test_editing_content_writes_a_new_version(): void
    {
        $template = $this->templates()->create($this->payload(), $this->owner);

        $this->templates()->update($template, ['body' => 'Revised body.']);

        $this->assertSame(2, $template->fresh()->versions()->count());
    }

    /** Publishing is not content — it must not flood the version history. */
    public function test_publishing_does_not_write_a_new_version(): void
    {
        $template = $this->templates()->create($this->payload(), $this->owner);

        $this->templates()->publish($template);

        $this->assertSame(1, $template->fresh()->versions()->count());
    }

    public function test_an_unpublished_template_is_not_findable_by_compose(): void
    {
        $this->templates()->create($this->payload(), $this->owner);

        $this->assertNull($this->templates()->find('custom_board_resolution'));
    }

    public function test_a_published_template_is_findable_by_compose(): void
    {
        $template = $this->templates()->create($this->payload(), $this->owner);
        $this->templates()->publish($template);

        $found = $this->templates()->find('custom_board_resolution');

        $this->assertSame('Board Resolution', $found['name']);
        $this->assertTrue($found['custom']);
    }

    public function test_unpublishing_removes_it_from_compose_again(): void
    {
        $template = $this->templates()->create($this->payload(), $this->owner);
        $this->templates()->publish($template);
        $this->templates()->unpublish($template->fresh());

        $this->assertNull($this->templates()->find('custom_board_resolution'));
    }

    /** A document can actually be composed from a published custom template. */
    public function test_a_document_can_be_composed_from_a_published_custom_template(): void
    {
        $template = $this->templates()->create($this->payload([
            'body' => 'Resolved that {{ subject }} is approved.',
            'fields' => [['key' => 'subject', 'label' => 'Subject', 'required' => true]],
        ]), $this->owner);
        $this->templates()->publish($template);

        $merged = app(DocumentComposer::class)->merge('custom_board_resolution', ['subject' => 'the budget'], $this->company);

        $this->assertStringContainsString('the budget is approved', $merged);
    }

    public function test_composing_from_an_unknown_template_still_throws(): void
    {
        $this->expectException(RuntimeException::class);

        app(DocumentComposer::class)->merge('not-a-real-template', [], $this->company);
    }

    /** A built-in template still resolves once no custom template shadows it. */
    public function test_a_built_in_template_still_composes_normally(): void
    {
        $merged = app(DocumentComposer::class)->merge('service_agreement', [
            'client_name' => 'A Client',
            'services' => 'Consulting',
            'fee' => '$1,000',
            'start_date' => '2026-01-01',
        ], $this->company);

        $this->assertStringContainsString('A Client', $merged);
    }

    public function test_all_published_as_array_only_includes_published_ones(): void
    {
        $published = $this->templates()->create($this->payload(['key' => 'published_one', 'name' => 'Published']), $this->owner);
        $this->templates()->publish($published);
        $this->templates()->create($this->payload(['key' => 'draft_one', 'name' => 'Draft']), $this->owner);

        $all = $this->templates()->allPublishedAsArray();

        $this->assertArrayHasKey('published_one', $all);
        $this->assertArrayNotHasKey('draft_one', $all);
    }

    protected function payload(array $overrides = []): array
    {
        return array_merge([
            'key' => 'custom_board_resolution',
            'name' => 'Board Resolution',
            'summary' => 'A formal board decision.',
            'body' => 'Resolved that the matter is approved.',
            'fields' => [],
        ], $overrides);
    }

    protected function templates(): CustomDocumentTemplates
    {
        return app(CustomDocumentTemplates::class);
    }
}
