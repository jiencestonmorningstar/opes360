<?php

namespace Tests\Feature\Documents;

use App\Livewire\Papers\Compose;
use App\Services\Documents\CustomDocumentTemplates;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The Papers gallery has offered a business's own published templates since
 * the previous commit — but the screen a tile actually opens, Compose,
 * resolved templates through App\Support\DocumentTemplates alone, so
 * clicking one of those tiles 404'd. Caught while building the field
 * registry, which touches the same resolution path.
 */
class ComposeCustomTemplateTest extends DocumentsTestCase
{
    public function test_a_published_custom_template_opens_in_compose(): void
    {
        $this->actingAs($this->owner);
        $template = app(CustomDocumentTemplates::class)->create([
            'key' => 'welcome_letter',
            'name' => 'Welcome Letter',
            'body' => 'Welcome, {{ name }}.',
            'fields' => [['key' => 'name', 'label' => 'Name', 'required' => true]],
        ], $this->owner);
        app(CustomDocumentTemplates::class)->publish($template);

        Livewire::test(Compose::class, ['template' => 'welcome_letter'])
            ->assertOk()
            ->assertSet('title', 'Welcome Letter');
    }

    public function test_an_unpublished_custom_template_404s_in_compose(): void
    {
        $this->actingAs($this->owner);
        app(CustomDocumentTemplates::class)->create([
            'key' => 'draft_letter',
            'name' => 'Draft Letter',
            'body' => 'Draft.',
        ], $this->owner);

        $this->expectAbort(404);

        Livewire::test(Compose::class, ['template' => 'draft_letter']);
    }

    public function test_a_document_can_be_saved_from_a_custom_template(): void
    {
        $this->actingAs($this->owner);
        $template = app(CustomDocumentTemplates::class)->create([
            'key' => 'welcome_letter',
            'name' => 'Welcome Letter',
            'body' => 'Welcome, {{ name }}.',
            'fields' => [['key' => 'name', 'label' => 'Name', 'required' => true]],
        ], $this->owner);
        app(CustomDocumentTemplates::class)->publish($template);

        Livewire::test(Compose::class, ['template' => 'welcome_letter'])
            ->set('title', 'For Jane')
            ->set('fields.name', 'Jane')
            ->call('save', false);

        $this->assertDatabaseHas('business_documents', ['template' => 'welcome_letter', 'title' => 'For Jane']);
    }

    protected function expectAbort(int $status): void
    {
        $this->withoutExceptionHandling();
        $this->expectException(NotFoundHttpException::class);
    }
}
