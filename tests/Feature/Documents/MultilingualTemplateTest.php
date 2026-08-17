<?php

namespace Tests\Feature\Documents;

use App\Livewire\Papers\Compose;
use App\Models\BusinessDocument;
use App\Services\DocumentComposer;
use App\Services\Documents\CustomDocumentTemplates;
use Livewire\Livewire;

/**
 * §44 — per-language body variants on a business's own templates.
 *
 * Every test here uses a custom template because the built-in catalogue is
 * pure PHP and never touches business_document_template_translations at all
 * — that untouched behaviour is exactly what test_composing_a_single_body_...
 * below is guarding.
 */
class MultilingualTemplateTest extends DocumentsTestCase
{
    public function test_composing_with_the_companys_default_language_picks_the_matching_variant(): void
    {
        $this->company->forceFill(['language' => 'fr'])->save();
        $template = $this->translatedTemplate();

        $body = app(DocumentComposer::class)->merge(
            'welcome_letter',
            ['name' => 'Jane'],
            $this->company,
        );

        $this->assertSame('Bienvenue, Jane.', $body);
    }

    public function test_composing_with_an_explicit_language_override(): void
    {
        $this->company->forceFill(['language' => 'en'])->save();
        $this->translatedTemplate();

        $body = app(DocumentComposer::class)->merge(
            'welcome_letter',
            ['name' => 'Jane'],
            $this->company,
            language: 'fr',
        );

        $this->assertSame('Bienvenue, Jane.', $body);
    }

    public function test_composing_falls_back_to_the_default_body_when_the_language_has_no_variant(): void
    {
        $this->translatedTemplate();

        $body = app(DocumentComposer::class)->merge(
            'welcome_letter',
            ['name' => 'Jane'],
            $this->company,
            language: 'de',
        );

        $this->assertSame('Welcome, Jane.', $body);
        $this->assertNotSame('', trim($body));
    }

    public function test_the_documents_language_is_set_from_what_was_actually_composed(): void
    {
        $this->company->forceFill(['language' => 'en'])->save();
        $this->translatedTemplate();
        $this->actingAs($this->owner);

        Livewire::test(Compose::class, ['template' => 'welcome_letter'])
            ->set('title', 'For Jane')
            ->set('fields.name', 'Jane')
            ->set('language', 'fr')
            ->call('save', false);

        $document = BusinessDocument::where('title', 'For Jane')->firstOrFail();

        $this->assertSame('fr', $document->language);
        $this->assertSame('Bienvenue, Jane.', $document->body);
    }

    public function test_falling_back_still_records_a_language_rather_than_leaving_it_null(): void
    {
        $this->company->forceFill(['language' => 'en'])->save();
        $this->translatedTemplate();
        $this->actingAs($this->owner);

        Livewire::test(Compose::class, ['template' => 'welcome_letter'])
            ->set('title', 'For Bob')
            ->set('fields.name', 'Bob')
            ->set('language', 'de')
            ->call('save', false);

        $document = BusinessDocument::where('title', 'For Bob')->firstOrFail();

        $this->assertNotNull($document->language);
        $this->assertSame('Welcome, Bob.', $document->body);
    }

    /** No translations at all: identical to composing before §44 existed. */
    public function test_a_single_body_template_composes_exactly_as_before(): void
    {
        $this->actingAs($this->owner);
        $template = app(CustomDocumentTemplates::class)->create([
            'key' => 'plain_letter',
            'name' => 'Plain Letter',
            'body' => 'Dear {{ name }}.',
            'fields' => [['key' => 'name', 'label' => 'Name', 'required' => true]],
        ], $this->owner);
        app(CustomDocumentTemplates::class)->publish($template);

        $body = app(DocumentComposer::class)->merge('plain_letter', ['name' => 'Jane'], $this->company);

        $this->assertSame('Dear Jane.', $body);

        Livewire::test(Compose::class, ['template' => 'plain_letter'])
            ->set('title', 'Plain')
            ->set('fields.name', 'Jane')
            ->call('save', false);

        $this->assertDatabaseHas('business_documents', ['title' => 'Plain', 'body' => 'Dear Jane.']);
    }

    /** The built-in catalogue never gains variants — merge() ignores $language for it. */
    public function test_a_built_in_template_ignores_the_language_argument(): void
    {
        $bodyDefault = app(DocumentComposer::class)->merge(
            'service_agreement',
            ['client_name' => 'Jane', 'provider_name' => 'Acme', 'scope' => 'Consulting'],
            $this->company,
        );

        $bodyWithLanguage = app(DocumentComposer::class)->merge(
            'service_agreement',
            ['client_name' => 'Jane', 'provider_name' => 'Acme', 'scope' => 'Consulting'],
            $this->company,
            language: 'fr',
        );

        $this->assertSame($bodyDefault, $bodyWithLanguage);
    }

    protected function translatedTemplate()
    {
        $template = app(CustomDocumentTemplates::class)->create([
            'key' => 'welcome_letter',
            'name' => 'Welcome Letter',
            'body' => 'Welcome, {{ name }}.',
            'fields' => [['key' => 'name', 'label' => 'Name', 'required' => true]],
        ], $this->owner);
        app(CustomDocumentTemplates::class)->publish($template);
        app(CustomDocumentTemplates::class)->setTranslation($template->fresh(), 'fr', 'Bienvenue, {{ name }}.');

        return $template->fresh();
    }
}
