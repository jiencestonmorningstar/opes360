<?php

namespace Tests\Unit;

use App\Support\DocumentNotes;
use PHPUnit\Framework\TestCase;

/**
 * Reading the structure back out of a plain textarea.
 *
 * What people type into a quotation's notes is a document — a scope, terms,
 * exclusions, a payment schedule. The parser's job is to notice that, using
 * only the conventions somebody would reach for anyway, and without requiring
 * anything already saved to be retyped.
 */
class DocumentNotesTest extends TestCase
{
    /** @return array<int, string> */
    protected function types(?string $notes): array
    {
        return array_column(DocumentNotes::parse($notes), 'type');
    }

    public function test_plain_prose_stays_paragraphs(): void
    {
        $blocks = DocumentNotes::parse("Thanks for your enquiry.\n\nWe look forward to working with you.");

        $this->assertSame(['paragraph', 'paragraph'], array_column($blocks, 'type'));
        $this->assertSame('Thanks for your enquiry.', $blocks[0]['text']);
    }

    /** Wrapped lines are one paragraph, not one paragraph per line. */
    public function test_wrapped_lines_join_into_one_paragraph(): void
    {
        $blocks = DocumentNotes::parse("The school hosts the application\non its own infrastructure.");

        $this->assertCount(1, $blocks);
        $this->assertSame('The school hosts the application on its own infrastructure.', $blocks[0]['text']);
    }

    public function test_a_line_in_capitals_is_a_heading(): void
    {
        $blocks = DocumentNotes::parse("PAYMENT TERMS\n\n60% on acceptance.");

        $this->assertSame(['heading', 'paragraph'], array_column($blocks, 'type'));
        $this->assertSame('PAYMENT TERMS', $blocks[0]['text']);
    }

    /**
     * A product name inside an otherwise capitalised heading must not demote
     * it — "SaaS" is two lowercase letters, not a change of intent.
     */
    public function test_a_heading_survives_a_mixed_case_product_name(): void
    {
        $blocks = DocumentNotes::parse('OPTION 2 — ONLINE SaaS + MOBILE APP');

        $this->assertSame(['heading'], array_column($blocks, 'type'));
    }

    /** A shouted sentence is emphasis, not a section title. */
    public function test_a_long_line_of_capitals_is_not_a_heading(): void
    {
        $line = 'THIS QUOTATION IS VALID FOR THIRTY DAYS AND PRICES MAY BE REVISED AFTERWARDS';

        $this->assertSame(['paragraph'], $this->types($line));
    }

    public function test_bulleted_lines_become_one_list(): void
    {
        $blocks = DocumentNotes::parse("  • Cloud hosting\n  • Regular backups\n  • Technical support");

        $this->assertSame(['bullets'], array_column($blocks, 'type'));
        $this->assertSame(['Cloud hosting', 'Regular backups', 'Technical support'], $blocks[0]['items']);
    }

    public function test_hyphens_and_asterisks_are_bullets_too(): void
    {
        $this->assertSame(['bullets'], $this->types("- One\n- Two"));
        $this->assertSame(['bullets'], $this->types("* One\n* Two"));
    }

    public function test_numbered_lines_become_an_ordered_list(): void
    {
        $blocks = DocumentNotes::parse("1. Requirements\n2. Configuration\n3. Go-live");

        $this->assertSame(['numbers'], array_column($blocks, 'type'));
        $this->assertSame(['Requirements', 'Configuration', 'Go-live'], $blocks[0]['items']);
    }

    public function test_a_run_of_dashes_is_a_rule(): void
    {
        $this->assertSame(['rule'], $this->types('--------'));
        $this->assertSame(['rule'], $this->types('════════'));
        $this->assertSame(['rule'], $this->types('────────'));
    }

    /** A bullet list and a numbered list must not merge into one. */
    public function test_two_kinds_of_list_stay_separate(): void
    {
        $this->assertSame(['bullets', 'numbers'], $this->types("• One\n1. Two"));
    }

    /**
     * The bug this guards: `\R` matched over bytes accepts 0x85 as a line
     * break, and 0x85 is the third byte of ★ (U+2605). A heading of
     * "★ RECOMMENDED" was being split down the middle of the star, leaving
     * half a character on one line and the word on the next.
     */
    public function test_a_multibyte_character_is_not_split_down_the_middle(): void
    {
        $blocks = DocumentNotes::parse('★ RECOMMENDED');

        $this->assertCount(1, $blocks);
        $this->assertSame('★ RECOMMENDED', $blocks[0]['text']);
        $this->assertTrue(mb_check_encoding($blocks[0]['text'], 'UTF-8'));
    }

    public function test_accented_text_survives(): void
    {
        $blocks = DocumentNotes::parse('Livraison à Yaoundé — coût inclus.');

        $this->assertSame('Livraison à Yaoundé — coût inclus.', $blocks[0]['text']);
    }

    public function test_empty_notes_produce_nothing(): void
    {
        $this->assertSame([], DocumentNotes::parse(null));
        $this->assertSame([], DocumentNotes::parse('   '));
    }

    public function test_structure_is_detectable(): void
    {
        $this->assertFalse(DocumentNotes::isStructured('Just a sentence.'));
        $this->assertTrue(DocumentNotes::isStructured("TERMS\n\nPay on time."));
    }
}
