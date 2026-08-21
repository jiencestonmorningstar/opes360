<?php

namespace App\Services\Documents;

use App\Models\BusinessDocument;
use App\Models\BusinessDocumentSignature;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Collection;

/**
 * §19's last remnant: a signature block rendered at a specific point in the
 * document body, rather than the round sitting only beside the document.
 *
 * A signature carries the same `data-block-id` the editor already stamps for
 * comment anchoring, so this needed no editor work and a signature and a
 * comment point at a block the same way. Where a signature names a block, its
 * block is rendered immediately after that block — so "signed here, under this
 * clause" is what the printed sheet actually shows.
 *
 * Runs on the rendered HTML rather than on the stored body: the stored body is
 * what the author wrote, and a signature is not authored content. Injecting it
 * on save would put it inside the tamper hash and make a signature look like
 * an edit to the document it is signing.
 */
class SignatureBlocks
{
    /**
     * Insert each anchored signature after the block it names.
     *
     * Unanchored signatures are untouched — they belong to the document as a
     * whole and are already shown beside it.
     */
    public function injectInto(string $html, BusinessDocument $document): string
    {
        if (trim($html) === '') {
            return $html;
        }

        $anchored = $document->signatures()
            ->whereNotNull('anchor_id')
            ->orderBy('order')
            ->get()
            ->groupBy('anchor_id');

        if ($anchored->isEmpty()) {
            return $html;
        }

        $doc = new DOMDocument;

        // Same posture as HtmlSanitizer: the body is arbitrary authored markup
        // and this must survive it rather than warn about it.
        libxml_use_internal_errors(true);
        $doc->loadHTML(
            '<?xml encoding="utf-8"?><body>'.$html.'</body>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );
        libxml_clear_errors();

        $body = $doc->getElementsByTagName('body')->item(0);

        if ($body === null) {
            return $html;
        }

        $xpath = new DOMXPath($doc);

        foreach ($anchored as $anchorId => $signatures) {
            $matches = $xpath->query('//*[@data-block-id="'.$this->escapeForXpath((string) $anchorId).'"]');

            if ($matches === false || $matches->length === 0) {
                /*
                 * The block was deleted after the signature was requested.
                 * The signature is not dropped — it is still a real request
                 * against this document, and it still shows beside the
                 * document. Only its placement is lost, which is the correct
                 * degradation: losing a signature because someone edited a
                 * paragraph would be far worse than losing its position.
                 */
                continue;
            }

            $target = $matches->item(0);

            if (! $target instanceof DOMElement || $target->parentNode === null) {
                continue;
            }

            $block = $this->buildBlock($doc, $signatures);

            if ($target->nextSibling !== null) {
                $target->parentNode->insertBefore($block, $target->nextSibling);
            } else {
                $target->parentNode->appendChild($block);
            }
        }

        $out = '';

        foreach (iterator_to_array($body->childNodes) as $child) {
            $out .= $doc->saveHTML($child);
        }

        return $out;
    }

    /** @param  Collection<int, BusinessDocumentSignature>  $signatures */
    protected function buildBlock(DOMDocument $doc, Collection $signatures): DOMElement
    {
        $wrap = $doc->createElement('div');
        $wrap->setAttribute('class', 'signature-block');

        foreach ($signatures as $signature) {
            $slot = $doc->createElement('div');
            $slot->setAttribute('class', 'signature-slot signature-'.$signature->status);

            $rule = $doc->createElement('div');
            $rule->setAttribute('class', 'signature-rule');
            $slot->appendChild($rule);

            $name = $doc->createElement('div');
            $name->setAttribute('class', 'signature-name');
            $name->appendChild($doc->createTextNode((string) $signature->signer_name));
            $slot->appendChild($name);

            $meta = $doc->createElement('div');
            $meta->setAttribute('class', 'signature-meta');
            $meta->appendChild($doc->createTextNode($this->caption($signature)));
            $slot->appendChild($meta);

            $wrap->appendChild($slot);
        }

        return $wrap;
    }

    /**
     * What sits under the line.
     *
     * A pending slot says so rather than printing an empty rule: a blank line
     * on a sheet reads as "sign here", and a document that has already been
     * declined must not invite a signature it will not accept.
     */
    protected function caption(BusinessDocumentSignature $signature): string
    {
        return match ($signature->status) {
            'signed' => 'Signed '.($signature->signed_at?->format('j F Y') ?? ''),
            'declined' => 'Declined'.($signature->declined_reason ? ' — '.$signature->declined_reason : ''),
            default => 'Awaiting signature',
        };
    }

    /** Quote a value safely for an XPath string literal. */
    protected function escapeForXpath(string $value): string
    {
        // Block ids are generated by the editor from [a-z0-9] only, so a quote
        // cannot occur — but this is user-reachable data reaching a query
        // language, and "cannot occur" is not a thing to rely on.
        return preg_replace('/[^A-Za-z0-9_-]/', '', $value) ?? '';
    }
}
