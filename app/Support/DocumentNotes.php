<?php

namespace App\Support;

/**
 * Turns the plain text of a document's notes into structured blocks.
 *
 * ── Why this exists ─────────────────────────────────────────────────────────
 *
 * `notes` is a textarea. What people type into it on a quotation is not a
 * paragraph — it is a document: a scope, a list of what is included, payment
 * terms, exclusions, validity. Rendered as one pre-wrapped block it is
 * technically all there and practically unreadable, and a quotation nobody can
 * read is a quotation nobody signs.
 *
 * So the text is parsed back into the structure it was plainly written with,
 * and the views render that structure properly. Nothing new has to be typed and
 * nothing already saved has to change: the conventions below are the ones
 * people already reach for in a plain textarea.
 *
 *   A LINE IN CAPITALS          a heading
 *   ─────  or  -----  or  ===   a rule between sections
 *   • item   - item   * item    a bulleted list
 *   1. item                     a numbered list
 *   blank line                  a paragraph break
 *
 * Everything else is a paragraph. Nothing is required — notes typed as plain
 * prose come back as plain paragraphs, which is what they are.
 *
 * The parser returns data rather than HTML on purpose: the screen and the
 * printed page style these differently, and a function that returned markup
 * would have to pick one.
 */
class DocumentNotes
{
    /**
     * @return array<int, array{type: string, text?: string, items?: array<int, string>}>
     */
    public static function parse(?string $notes): array
    {
        $notes = trim((string) $notes);

        if ($notes === '') {
            return [];
        }

        $blocks = [];
        $paragraph = [];
        $list = null;

        $flushParagraph = function () use (&$paragraph, &$blocks): void {
            if ($paragraph !== []) {
                $blocks[] = ['type' => 'paragraph', 'text' => implode(' ', $paragraph)];
                $paragraph = [];
            }
        };

        $flushList = function () use (&$list, &$blocks): void {
            if ($list !== null) {
                $blocks[] = $list;
                $list = null;
            }
        };

        /*
         * The `u` matters more than it looks. Without it `\R` is matched over
         * bytes, and one of the newline bytes it accepts is 0x85 — which is
         * also the third byte of ★ (U+2605) and of every other character whose
         * UTF-8 encoding ends there. A heading of "★ RECOMMENDED" was being
         * split down the middle of the star, leaving half a character on one
         * line and the word on the next.
         */
        foreach (preg_split('/\R/u', $notes) as $raw) {
            $line = trim($raw);

            // Blank line: ends whatever was open.
            if ($line === '') {
                $flushParagraph();
                $flushList();

                continue;
            }

            // Box-drawing horizontals (─ ━ ═), hyphens, equals and underscores
            // are all the same intent: a divider between sections.
            if (preg_match('/^[─━═\-=_]{3,}$/u', $line) === 1) {
                $flushParagraph();
                $flushList();
                $blocks[] = ['type' => 'rule'];

                continue;
            }

            // Bulleted item.
            if (preg_match('/^[•·*\-]\s+(.+)$/u', $line, $m) === 1) {
                $flushParagraph();

                if ($list === null || $list['type'] !== 'bullets') {
                    $flushList();
                    $list = ['type' => 'bullets', 'items' => []];
                }

                $list['items'][] = trim($m[1]);

                continue;
            }

            // Numbered item.
            if (preg_match('/^\d+[.)]\s+(.+)$/u', $line, $m) === 1) {
                $flushParagraph();

                if ($list === null || $list['type'] !== 'numbers') {
                    $flushList();
                    $list = ['type' => 'numbers', 'items' => []];
                }

                $list['items'][] = trim($m[1]);

                continue;
            }

            /*
             * A heading: a short line carrying no lowercase letters. Length is
             * part of the test because a whole sentence shouted in capitals is
             * emphasis, not a section title, and turning it into one would
             * scatter headings through the middle of somebody's prose.
             */
            if (self::isHeading($line)) {
                $flushParagraph();
                $flushList();
                $blocks[] = ['type' => 'heading', 'text' => $line];

                continue;
            }

            $flushList();
            $paragraph[] = $line;
        }

        $flushParagraph();
        $flushList();

        return $blocks;
    }

    protected static function isHeading(string $line): bool
    {
        if (mb_strlen($line) > 70) {
            return false;
        }

        preg_match_all('/\p{Lu}/u', $line, $upper);
        preg_match_all('/\p{Ll}/u', $line, $lower);

        $uppers = count($upper[0]);
        $lowers = count($lower[0]);

        if ($uppers === 0) {
            return false;
        }

        /*
         * Mostly capitals rather than entirely capitals. A heading like
         * "OPTION 2 — ONLINE SaaS + TEACHER MOBILE APP" is written in capitals
         * by anyone's reading of it, and demanding purity would demote it to a
         * paragraph over the two lowercase letters in a product name.
         */
        return $uppers / max(1, $uppers + $lowers) >= 0.8;
    }

    /** Whether the text has any structure worth rendering as such. */
    public static function isStructured(?string $notes): bool
    {
        foreach (self::parse($notes) as $block) {
            if ($block['type'] !== 'paragraph') {
                return true;
            }
        }

        return false;
    }
}
