<?php

namespace Tests\Feature\Documents;

use App\Livewire\Papers\Compose;
use App\Models\BusinessDocument;
use App\Models\BusinessDocumentTemplate;
use App\Services\DocumentComposer;
use App\Services\Documents\CustomDocumentTemplates;
use App\Support\CurrentCompany;
use Livewire\Livewire;

/**
 * Multilingual custom templates — §44. The `language` column on
 * business_documents has existed since the sales-ledger-shaped migration and
 * done nothing; this is what finally sets it.
 */
class TemplateTranslationTest extends DocumentsTestCase
{
    public function test_composing_with_the_companys_default_language_picks_the_matching_variant(): void
    {
        $this->company->forceFill(['language' => 'fr'])->save();
        $template = $this->translatedTemplate();

        $body = app(DocumentComposer::class)->merge(
            'bilingual_letter',
            ['name' => 'Jean'],
            $this->company->fresh(),
        );

        $this->assertStringContainsString('Bonjour, Jean.', $body);
    }

    public function test_composing_with_an_explicit_language_override(): void
    {
        $this->translatedTemplate();

        $body = app(DocumentComposer::class)->merge(
            'bilingual_letter',
            ['name' => 'Jane'],
            $this->company,
            language: 'es',
        );

        $this->assertStringContainsString('Hola, Jane.', $body);
    }

    public function test_falling_back_to_the_default_body_when_the_chosen_language_has_no_variant(): void
    {
        $this->translatedTemplate();

        $body = app(DocumentComposer::class)->merge(
            'bilingual_letter',
            ['name' => 'Amina'],
            $this->company,
            language: 'de',
        );

        $this->assertNotSame('', trim($body));
        $this->assertStringContainsString('Hello, Amina.', $body);
    }

    public function test_language_is_set_after_composing_a_multi_variant_template(): void
    {
        $this->actingAs($this->owner);
        $this->company->forceFill(['language' => 'en'])->save();
        $this->translatedTemplate();

        Livewire::test(Compose::class, ['template' => 'bilingual_letter'])
            ->set('title', 'For Jane')
            ->set('fields.name', 'Jane')
            ->set('language', 'es')
            ->call('save', false);

        $this->assertDatabaseHas('business_documents', [
            'template' => 'bilingual_letter',
            'title' => 'For Jane',
            'language' => 'es',
        ]);
    }

    public function test_language_stays_null_for_a_single_body_custom_template(): void
    {
        $this->actingAs($this->owner);
        $template = app(CustomDocumentTemplates::class)->create([
            'key' => 'plain_letter',
            'name' => 'Plain Letter',
            'body' => 'Dear {{ name }}.',
            'fields' => [['key' => 'name', 'label' => 'Name', 'required' => true]],
        ], $this->owner);
        app(CustomDocumentTemplates::class)->publish($template);

        Livewire::test(Compose::class, ['template' => 'plain_letter'])
            ->set('title', 'For Bob')
            ->set('fields.name', 'Bob')
            ->call('save', false);

        $document = BusinessDocument::where('title', 'For Bob')->firstOrFail();
        $this->assertNull($document->language);
    }

    /** Regression: an ordinary built-in template is entirely unaffected by §44. */
    public function test_a_built_in_template_composes_unaffected_and_leaves_language_null(): void
    {
        $body = app(DocumentComposer::class)->merge(
            'service_agreement',
            ['client_name' => 'Un Client'],
            $this->company,
        );

        $this->assertNotSame('', trim($body));
        $this->assertNull(app(DocumentComposer::class)->resolveLanguage('service_agreement', null, $this->company));
    }

    protected function translatedTemplate(): BusinessDocumentTemplate
    {
        app(CurrentCompany::class)->set($this->company);

        $template = app(CustomDocumentTemplates::class)->create([
            'key' => 'bilingual_letter',
            'name' => 'Bilingual Letter',
            'body' => 'Hello, {{ name }}.',
            'fields' => [['key' => 'name', 'label' => 'Name', 'required' => true]],
        ], $this->owner);
        app(CustomDocumentTemplates::class)->publish($template);

        app(CustomDocumentTemplates::class)->setTranslation($template, 'fr', 'Bonjour, {{ name }}.');
        app(CustomDocumentTemplates::class)->setTranslation($template, 'es', 'Hola, {{ name }}.');

        return $template->fresh();
    }
}
