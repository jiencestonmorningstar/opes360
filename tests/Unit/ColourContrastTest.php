<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The design tokens, held to WCAG 2.1 contrast minimums.
 *
 * These are read out of resources/css/app.css rather than duplicated here, so
 * the test measures what actually ships. It exists because the palette had
 * drifted to a state nobody could see by eye but every user could: orange
 * captions at 2.49:1, white button labels on teal at 3.74:1, and a `faint`
 * text token that was invisible on every surface it was used on.
 *
 * The rule the tokens follow is in the file's own comments: `--color-<hue>` is
 * the ink role and must survive on pale surfaces; `--color-fill-<hue>` is the
 * fill role and must survive under white text. Nothing here is aesthetic — it
 * only asserts that text can be read.
 */
class ColourContrastTest extends TestCase
{
    /** WCAG 2.1 AA, normal-size text. */
    private const AA = 4.5;

    /** WCAG 2.1 AA, large text (≥18.66px bold or ≥24px) and UI components. */
    private const AA_LARGE = 3.0;

    /** @var array<string, array<string, string>> theme => token => hex */
    private static array $tokens;

    public static function setUpBeforeClass(): void
    {
        $css = file_get_contents(__DIR__.'/../../resources/css/app.css');

        self::$tokens = [
            'light' => self::parseBlock($css, '@theme'),
            // The dark theme only re-points what changes, so start from light
            // and overlay — exactly how the cascade resolves it in a browser.
            'dark' => [],
        ];

        self::$tokens['dark'] = [...self::$tokens['light'], ...self::parseBlock($css, '.dark')];
    }

    /** Pulls `--color-*: #hex;` declarations out of the named top-level block. */
    private static function parseBlock(string $css, string $selector): array
    {
        $start = strpos($css, $selector.' {');
        if ($start === false) {
            throw new \RuntimeException("Could not find [{$selector}] in app.css.");
        }

        $depth = 0;
        $end = $start;
        for ($i = strpos($css, '{', $start); $i < strlen($css); $i++) {
            if ($css[$i] === '{') {
                $depth++;
            } elseif ($css[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    $end = $i;
                    break;
                }
            }
        }

        preg_match_all(
            '/--color-([a-z0-9-]+):\s*(#[0-9a-fA-F]{6})/',
            substr($css, $start, $end - $start),
            $matches,
            PREG_SET_ORDER
        );

        return array_reduce($matches, function (array $carry, array $m) {
            $carry[$m[1]] = strtolower($m[2]);

            return $carry;
        }, []);
    }

    private function colour(string $theme, string $token): string
    {
        $this->assertArrayHasKey($token, self::$tokens[$theme], "Token --color-{$token} is not defined for the {$theme} theme.");

        return self::$tokens[$theme][$token];
    }

    /** Relative luminance, per WCAG 2.1 definition. */
    private function luminance(string $hex): float
    {
        $channels = array_map(function (string $pair) {
            $c = hexdec($pair) / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, str_split(ltrim($hex, '#'), 2));

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    private function ratio(string $a, string $b): float
    {
        $la = $this->luminance($a);
        $lb = $this->luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    private function assertContrast(string $theme, string $fg, string $bg, float $minimum, string $because): void
    {
        $fgHex = $this->colour($theme, $fg);
        $bgHex = $this->colour($theme, $bg);
        $ratio = $this->ratio($fgHex, $bgHex);

        $this->assertGreaterThanOrEqual(
            $minimum,
            round($ratio, 2),
            sprintf(
                '[%s] %s (%s) on %s (%s) is %.2f:1, below the %.1f:1 needed — %s',
                $theme, $fg, $fgHex, $bg, $bgHex, $ratio, $minimum, $because
            )
        );
    }

    public static function themes(): array
    {
        return ['light' => ['light'], 'dark' => ['dark']];
    }

    /**
     * @dataProvider themes
     */
    public function test_the_text_ramp_is_readable_on_every_surface(string $theme): void
    {
        foreach (['ink', 'ink-2', 'muted', 'faint'] as $text) {
            foreach (['canvas', 'surface', 'surface-2'] as $surface) {
                $this->assertContrast($theme, $text, $surface, self::AA,
                    'every level of the text ramp is used for body copy somewhere.');
            }
        }
    }

    /**
     * @dataProvider themes
     */
    public function test_semantic_ink_is_readable_on_every_surface(string $theme): void
    {
        foreach (['brand', 'positive', 'warning', 'negative'] as $semantic) {
            foreach (['canvas', 'surface', 'surface-2'] as $surface) {
                $this->assertContrast($theme, $semantic, $surface, self::AA,
                    'these are used as link and status text, not only as decoration.');
            }
        }
    }

    /**
     * @dataProvider themes
     */
    public function test_accent_ink_is_readable_on_its_own_tint(string $theme): void
    {
        foreach (['blue', 'green', 'orange', 'purple', 'teal', 'pink', 'slate'] as $hue) {
            $this->assertContrast($theme, "accent-{$hue}", "tint-{$hue}", self::AA,
                'icon bubbles pair the accent glyph with the tint of the same hue.');
        }
    }

    /**
     * @dataProvider themes
     */
    public function test_semantic_ink_is_readable_on_the_matching_tint(string $theme): void
    {
        foreach ([['positive', 'tint-green'], ['warning', 'tint-orange'], ['negative', 'tint-red'], ['brand', 'tint-blue']] as [$semantic, $tint]) {
            $this->assertContrast($theme, $semantic, $tint, self::AA,
                'status chips put semantic text on a tinted pill.');
        }
    }

    /**
     * @dataProvider themes
     */
    public function test_white_text_survives_on_every_fill(string $theme): void
    {
        $fills = array_filter(
            array_keys(self::$tokens[$theme]),
            fn (string $token) => str_starts_with($token, 'fill-')
        );

        $this->assertNotEmpty($fills, 'No fill tokens found — the parser is looking at the wrong block.');

        foreach ($fills as $fill) {
            $ratio = $this->ratio('#ffffff', $this->colour($theme, $fill));

            $this->assertGreaterThanOrEqual(self::AA, round($ratio, 2), sprintf(
                '[%s] white on %s (%s) is %.2f:1 — a fill exists precisely to carry white text.',
                $theme, $fill, $this->colour($theme, $fill), $ratio
            ));
        }
    }

    /**
     * @dataProvider themes
     */
    public function test_surfaces_are_distinguishable_from_each_other(string $theme): void
    {
        // Not a WCAG rule, but the reason a card is visible at all: if canvas
        // and surface collapse to the same value the whole elevation system
        // disappears and the page reads as one flat sheet.
        foreach ([['canvas', 'surface'], ['surface', 'surface-2']] as [$a, $b]) {
            $this->assertNotSame(
                $this->colour($theme, $a),
                $this->colour($theme, $b),
                "[{$theme}] {$a} and {$b} are the same colour, so elevation is invisible."
            );
        }
    }

    /**
     * @dataProvider themes
     */
    public function test_borders_separate_a_card_from_what_is_behind_it(string $theme): void
    {
        $this->assertContrast($theme, 'border-strong', 'surface', 1.3,
            'the strong border is the one used for form controls and dividers that must be seen.');
    }
}
