<?php

namespace App\Services\Documents;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * Server-side allowlist sanitizer for rich-editor HTML.
 *
 * The editor's toolbar defines the vocabulary — headings, emphasis, lists,
 * tables, blockquotes, rules, links — and this class enforces it on save, so
 * the stored body can never carry more than the toolbar could have produced.
 * Allowlist, not blocklist: an unknown tag is unwrapped (its text survives,
 * its markup does not), an unknown attribute is dropped, and script/style
 * bodies are removed outright. That is the only shape of sanitizer that stays
 * safe when browsers grow new event handlers or new URL schemes.
 */
class HtmlSanitizer
{
    /** Tags the editor can produce. Everything else is unwrapped or dropped. */
    protected const ALLOWED = [
        'p', 'br', 'h1', 'h2', 'h3',
        'strong', 'b', 'em', 'i', 'u', 's',
        'ul', 'ol', 'li',
        'table', 'thead', 'tbody', 'tr', 'th', 'td',
        'blockquote', 'hr', 'a',
    ];

    /** Tags whose *content* is as unwanted as the tag itself. */
    protected const DROP_WITH_CONTENT = ['script', 'style', 'iframe', 'object', 'embed', 'form', 'svg', 'math', 'template', 'noscript'];

    /** Attributes kept, per tag. Nothing survives that is not listed here. */
    protected const ALLOWED_ATTRIBUTES = [
        'a' => ['href'],
        'th' => ['colspan', 'rowspan'],
        'td' => ['colspan', 'rowspan'],
    ];

    public function clean(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $doc = new DOMDocument;

        // Suppress warnings for the tag soup a paste can contain; the point of
        // this class is to survive exactly that input.
        libxml_use_internal_errors(true);
        $doc->loadHTML(
            '<?xml encoding="utf-8"?><body>'.$html.'</body>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );
        libxml_clear_errors();

        $body = $doc->getElementsByTagName('body')->item(0);

        if ($body === null) {
            return '';
        }

        $this->sanitizeChildren($body);

        $out = '';
        foreach (iterator_to_array($body->childNodes) as $child) {
            $out .= $doc->saveHTML($child);
        }

        return trim($out);
    }

    protected function sanitizeChildren(DOMNode $node): void
    {
        // Snapshot first: replacing/removing children while iterating a live
        // NodeList skips siblings.
        foreach (iterator_to_array($node->childNodes) as $child) {
            $this->sanitizeNode($child);
        }
    }

    protected function sanitizeNode(DOMNode $node): void
    {
        if ($node instanceof DOMText) {
            return;
        }

        if (! $node instanceof DOMElement) {
            // Comments, CDATA, processing instructions: none of them are
            // content a document editor produces.
            $node->parentNode?->removeChild($node);

            return;
        }

        $tag = strtolower($node->tagName);

        if (in_array($tag, self::DROP_WITH_CONTENT, true)) {
            $node->parentNode?->removeChild($node);

            return;
        }

        if (! in_array($tag, self::ALLOWED, true)) {
            // Unwrap: keep the (sanitized) children, lose the tag.
            $this->sanitizeChildren($node);

            $parent = $node->parentNode;
            foreach (iterator_to_array($node->childNodes) as $child) {
                $parent?->insertBefore($child, $node);
            }
            $parent?->removeChild($node);

            return;
        }

        $this->sanitizeAttributes($node, $tag);
        $this->sanitizeChildren($node);
    }

    protected function sanitizeAttributes(DOMElement $element, string $tag): void
    {
        $allowed = self::ALLOWED_ATTRIBUTES[$tag] ?? [];

        foreach (iterator_to_array($element->attributes) as $attribute) {
            if (! in_array(strtolower($attribute->name), $allowed, true)) {
                $element->removeAttribute($attribute->name);
            }
        }

        if ($tag === 'a') {
            $href = trim($element->getAttribute('href'));

            if (! $this->safeUrl($href)) {
                $element->removeAttribute('href');
            }

            // A stored document is shown to people other than its author, so
            // every link opens in a new tab without a window.opener handle.
            if ($element->hasAttribute('href')) {
                $element->setAttribute('rel', 'noopener noreferrer');
                $element->setAttribute('target', '_blank');
            }
        }
    }

    /** http, https and mailto only. javascript:, data: and friends never survive. */
    protected function safeUrl(string $url): bool
    {
        if ($url === '') {
            return false;
        }

        // No scheme (relative or scheme-relative) — nothing to abuse.
        if (! preg_match('/^\s*([a-z][a-z0-9+.\-]*):/i', $url, $m)) {
            return true;
        }

        return in_array(strtolower($m[1]), ['http', 'https', 'mailto'], true);
    }
}
