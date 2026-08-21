<?php

namespace Tests\Feature\Documents;

use App\Models\BusinessDocumentSignature;
use App\Services\Documents\DocumentSignatureRequests;
use App\Services\Documents\SignatureBlocks;

/**
 * §19's last remnant: a signature block rendered where it belongs in the text,
 * not only beside the document.
 */
class SignatureBlockPlacementTest extends DocumentsTestCase
{
    protected function blocks(): SignatureBlocks
    {
        return app(SignatureBlocks::class);
    }

    protected function body(): string
    {
        return '<p data-block-id="b1">The terms.</p>'
            .'<p data-block-id="b2">Signed for the client.</p>'
            .'<p data-block-id="b3">Afterwards.</p>';
    }

    public function test_an_anchored_signature_renders_after_its_block(): void
    {
        $doc = $this->document(['body' => $this->body()]);

        app(DocumentSignatureRequests::class)->request($doc, [
            ['name' => 'Nodai Felix', 'email' => 'n@example.test', 'anchor_id' => 'b2'],
        ]);

        $html = $this->blocks()->injectInto($this->body(), $doc->fresh());

        $this->assertStringContainsString('signature-block', $html);
        $this->assertStringContainsString('Nodai Felix', $html);

        // It must land after b2, not at the end and not before it.
        $afterB2 = strpos($html, 'Signed for the client.');
        $block = strpos($html, 'signature-block');
        $afterwards = strpos($html, 'Afterwards.');

        $this->assertGreaterThan($afterB2, $block, 'the block landed before its anchor');
        $this->assertLessThan($afterwards, $block, 'the block landed after the wrong paragraph');
    }

    public function test_an_unanchored_signature_is_not_injected(): void
    {
        $doc = $this->document(['body' => $this->body()]);

        app(DocumentSignatureRequests::class)->request($doc, [
            ['name' => 'Nodai Felix', 'email' => 'n@example.test'],
        ]);

        $this->assertStringNotContainsString(
            'signature-block',
            $this->blocks()->injectInto($this->body(), $doc->fresh()),
        );
    }

    public function test_a_pending_slot_says_so_rather_than_printing_a_blank_line(): void
    {
        $doc = $this->document(['body' => $this->body()]);

        app(DocumentSignatureRequests::class)->request($doc, [
            ['name' => 'Nodai Felix', 'email' => 'n@example.test', 'anchor_id' => 'b2'],
        ]);

        $this->assertStringContainsString(
            'Awaiting signature',
            $this->blocks()->injectInto($this->body(), $doc->fresh()),
        );
    }

    public function test_a_signed_slot_carries_the_date(): void
    {
        $doc = $this->document(['body' => $this->body()]);

        app(DocumentSignatureRequests::class)->request($doc, [
            ['name' => 'Nodai Felix', 'email' => 'n@example.test', 'anchor_id' => 'b2'],
        ]);

        BusinessDocumentSignature::first()->forceFill([
            'status' => 'signed',
            'signed_at' => now(),
        ])->save();

        $html = $this->blocks()->injectInto($this->body(), $doc->fresh());

        $this->assertStringContainsString('Signed '.now()->format('j F Y'), $html);
        $this->assertStringContainsString('signature-signed', $html);
    }

    /**
     * Losing a signature because somebody edited a paragraph would be far
     * worse than losing its position.
     */
    public function test_a_deleted_anchor_loses_the_placement_not_the_signature(): void
    {
        $doc = $this->document(['body' => $this->body()]);

        app(DocumentSignatureRequests::class)->request($doc, [
            ['name' => 'Nodai Felix', 'email' => 'n@example.test', 'anchor_id' => 'b2'],
        ]);

        $edited = '<p data-block-id="b1">The terms.</p>';

        $html = $this->blocks()->injectInto($edited, $doc->fresh());

        $this->assertStringNotContainsString('signature-block', $html);
        $this->assertSame(1, BusinessDocumentSignature::count(), 'the signature was destroyed');
    }

    public function test_two_signers_on_one_block_both_render_in_order(): void
    {
        $doc = $this->document(['body' => $this->body()]);

        app(DocumentSignatureRequests::class)->request($doc, [
            ['name' => 'First Signer', 'email' => 'a@example.test', 'anchor_id' => 'b2'],
            ['name' => 'Second Signer', 'email' => 'b@example.test', 'anchor_id' => 'b2'],
        ]);

        $html = $this->blocks()->injectInto($this->body(), $doc->fresh());

        $this->assertLessThan(
            strpos($html, 'Second Signer'),
            strpos($html, 'First Signer'),
            'signers rendered out of their round order',
        );
    }

    public function test_an_empty_body_is_returned_untouched(): void
    {
        $doc = $this->document(['body' => '']);

        $this->assertSame('', $this->blocks()->injectInto('', $doc));
    }

    /** The author's stored body must never gain a signature block. */
    public function test_the_stored_body_is_not_modified(): void
    {
        $doc = $this->document(['body' => $this->body()]);

        app(DocumentSignatureRequests::class)->request($doc, [
            ['name' => 'Nodai Felix', 'email' => 'n@example.test', 'anchor_id' => 'b2'],
        ]);

        $this->blocks()->injectInto($this->body(), $doc->fresh());

        $this->assertStringNotContainsString('signature-block', (string) $doc->fresh()->body);
    }

    public function test_a_hostile_anchor_id_cannot_break_the_query(): void
    {
        $doc = $this->document(['body' => $this->body()]);

        app(DocumentSignatureRequests::class)->request($doc, [
            ['name' => 'Nodai Felix', 'email' => 'n@example.test', 'anchor_id' => 'b2"] | //*'],
        ]);

        // Must not throw, and must not match every element in the document.
        $html = $this->blocks()->injectInto($this->body(), $doc->fresh());

        $this->assertLessThanOrEqual(1, substr_count($html, 'signature-block'));
    }
}
