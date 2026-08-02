<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Rules in the stylesheet that hold the whole app's layout up.
 *
 * Each of these is here because its absence produced a bug that looked like
 * something else entirely, and would do so again if the line were tidied away
 * as redundant. The test names say what breaks.
 */
class StylesheetInvariantsTest extends TestCase
{
    private static string $css;

    public static function setUpBeforeClass(): void
    {
        self::$css = file_get_contents(__DIR__.'/../../resources/css/app.css');
    }

    /**
     * Without this, a page whose layout forgets to paint a background falls
     * through to the browser default — white — while the text tokens have
     * already flipped to their dark values. That shipped on the login screen.
     */
    public function test_the_root_element_always_carries_a_page_background(): void
    {
        $this->assertMatchesRegularExpression(
            '/html\s*\{[^}]*background-color:\s*var\(--color-canvas\)/s',
            self::$css,
            'html lost its background floor: a layout that forgets to set one now renders white.'
        );
    }

    /**
     * A grid item's automatic minimum size is its min-content width, and a
     * track cannot be smaller than that — so one un-shrinkable child scrolls
     * the whole document sideways. Settings rendered 690px wide on a 360px
     * phone before this rule existed.
     */
    public function test_grid_items_are_allowed_to_shrink(): void
    {
        $this->assertMatchesRegularExpression(
            '/:where\(\.grid\)\s*>\s*\*\s*\{[^}]*min-width:\s*0/s',
            self::$css,
            'Grid children can no longer shrink below min-content; expect horizontal scroll on small screens.'
        );
    }

    /** Both roles must exist, or the contrast split silently collapses back. */
    public function test_the_ink_and_fill_token_families_both_exist(): void
    {
        foreach (['--color-brand:', '--color-fill-brand:', '--color-accent-green:', '--color-fill-green:'] as $token) {
            $this->assertStringContainsString($token, self::$css, "Missing design token [{$token}].");
        }
    }

    /** 44px is the minimum comfortable touch target; the whole app is thumb-driven. */
    public function test_the_tap_target_utility_still_clears_forty_four_pixels(): void
    {
        $this->assertMatchesRegularExpression(
            '/@utility tap \{[^}]*min-height:\s*44px[^}]*min-width:\s*44px/s',
            self::$css
        );
    }
}
