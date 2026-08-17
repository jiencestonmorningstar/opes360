<?php

namespace Tests\Feature\Documents;

use App\Models\Contact;
use App\Services\DocumentComposer;
use App\Services\Documents\DocumentLinker;
use App\Services\Documents\HtmlSanitizer;

/**
 * Field chips — §3.1/§3.2 of the master spec, scoped honestly: no
 * WebSocket push (that needs Reverb, see docs/handoff/rich-editor.md), just
 * a chip that re-resolves against its linked ERP record every time the
 * editor's body is rendered, instead of freezing the value the moment it
 * was inserted.
 */
class LiveTokenChipTest extends DocumentsTestCase
{
    protected function contact(string $name = 'Ada Lovelace'): Contact
    {
        return Contact::create([
            'name' => $name,
            'type' => 'customer',
        ]);
    }

    public function test_sanitizer_keeps_a_field_chip_span_but_strips_other_attributes(): void
    {
        $clean = app(HtmlSanitizer::class)->clean(
            '<p><span data-token="customer.name" class="chip" onclick="alert(1)">Stale</span></p>',
        );

        $this->assertSame('<p><span data-token="customer.name">Stale</span></p>', $clean);
    }

    public function test_sanitizer_unwraps_a_bare_span_with_no_token(): void
    {
        $clean = app(HtmlSanitizer::class)->clean('<p><span class="x">kept</span> text</p>');

        $this->assertSame('<p>kept text</p>', $clean);
    }

    public function test_html_body_resolves_a_field_chip_against_the_linked_customer(): void
    {
        $contact = $this->contact('Ada Lovelace');
        $paper = $this->document([
            'body' => '<p>Dear <span data-token="customer.name">Old Name</span>,</p>',
        ]);

        app(DocumentLinker::class)->attach($paper, $contact, 'about', $this->owner);

        $html = app(DocumentComposer::class)->toHtml($paper->body, $paper);

        $this->assertStringContainsString('<span data-token="customer.name">Ada Lovelace</span>', $html);
    }

    public function test_a_chip_still_shows_its_current_value_after_the_customer_is_renamed(): void
    {
        $contact = $this->contact('Ada Lovelace');
        $paper = $this->document([
            'body' => '<p><span data-token="customer.name">Ada Lovelace</span></p>',
        ]);
        app(DocumentLinker::class)->attach($paper, $contact, 'about', $this->owner);

        $contact->update(['name' => 'Ada, Countess of Lovelace']);

        $html = app(DocumentComposer::class)->toHtml($paper->body, $paper->fresh());

        $this->assertStringContainsString('Ada, Countess of Lovelace', $html);
        $this->assertStringNotContainsString('>Ada Lovelace<', $html);
    }

    public function test_an_unresolvable_chip_keeps_its_last_rendered_text(): void
    {
        // No linked record at all — the chip has nothing to resolve against.
        $paper = $this->document([
            'body' => '<p><span data-token="customer.name">Ada Lovelace</span></p>',
        ]);

        $html = app(DocumentComposer::class)->toHtml($paper->body, $paper);

        $this->assertStringContainsString('<span data-token="customer.name">Ada Lovelace</span>', $html);
    }

    public function test_to_html_without_a_document_leaves_chips_exactly_as_stored(): void
    {
        // Compose's live preview: no BusinessDocument exists yet.
        $html = app(DocumentComposer::class)->toHtml(
            '<p><span data-token="customer.name">Ada Lovelace</span></p>',
        );

        $this->assertStringContainsString('<span data-token="customer.name">Ada Lovelace</span>', $html);
    }

    public function test_available_tokens_lists_fields_for_the_linked_customer(): void
    {
        $contact = $this->contact('Ada Lovelace');
        $paper = $this->document();
        app(DocumentLinker::class)->attach($paper, $contact, 'about', $this->owner);

        $tokens = app(DocumentComposer::class)->availableTokens($paper);

        $this->assertArrayHasKey('customer.name', $tokens);
        $this->assertSame('Customer name', $tokens['customer.name']);
    }

    public function test_available_tokens_is_empty_for_an_unlinked_document(): void
    {
        $paper = $this->document();

        $this->assertSame([], app(DocumentComposer::class)->availableTokens($paper));
    }
}
