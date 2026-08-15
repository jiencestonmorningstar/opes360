<?php

namespace Tests\Unit;

use App\Support\Colour;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ColourTest extends TestCase
{
    public function test_hex_round_trips(): void
    {
        foreach (['#1db954', '#ffffff', '#000000', '#635bff'] as $hex) {
            $this->assertSame($hex, Colour::toHex(Colour::fromHex($hex)));
        }
    }

    public function test_shorthand_hex_expands(): void
    {
        $this->assertSame('#ffffff', Colour::toHex(Colour::fromHex('#fff')));
        $this->assertSame('#112233', Colour::toHex(Colour::fromHex('123')));
    }

    public function test_oklch_round_trips_within_one_eight_bit_step(): void
    {
        foreach (['#1db954', '#ff385c', '#4a154b', '#008060', '#eef2f7', '#ffff00'] as $hex) {
            $rgb = Colour::fromHex($hex);
            $back = Colour::fromOklch(Colour::toOklch($rgb));

            foreach ($rgb as $i => $channel) {
                $this->assertEqualsWithDelta($channel, $back[$i], 1 / 255, "{$hex} channel {$i}");
            }
        }
    }

    /** Published WCAG values. White against black is exactly 21:1. */
    public function test_contrast_matches_published_values(): void
    {
        $this->assertEqualsWithDelta(21.0, Colour::contrast('#ffffff', '#000000'), 0.01);
        $this->assertEqualsWithDelta(1.0, Colour::contrast('#abcdef', '#abcdef'), 0.001);
        $this->assertEqualsWithDelta(2.59, Colour::contrast('#1db954', '#ffffff'), 0.01);
        $this->assertEqualsWithDelta(4.18, Colour::contrast('#635bff', '#eef2f7'), 0.01);
    }

    /**
     * The mistake this guards: treating "brand text on white" and "white text on
     * brand" as two different ratios. They are one number.
     */
    public function test_contrast_is_symmetric(): void
    {
        $this->assertSame(
            Colour::contrast('#1db954', '#ffffff'),
            Colour::contrast('#ffffff', '#1db954'),
        );
    }

    public static function seeds(): array
    {
        return [
            'Spotify green' => ['#1db954'],
            'Airbnb red' => ['#ff385c'],
            'Stripe indigo' => ['#635bff'],
            'pure yellow' => ['#ffff00'],
            'pure cyan' => ['#00ffff'],
            'mid grey' => ['#808080'],
        ];
    }

    #[DataProvider('seeds')]
    public function test_darkening_reaches_the_target_against_canvas(string $seed): void
    {
        $out = Colour::toContrast($seed, '#eef2f7', 4.5, 'darken');

        $this->assertGreaterThanOrEqual(4.5, Colour::contrast($out, '#eef2f7'));
    }

    #[DataProvider('seeds')]
    public function test_lightening_reaches_the_target_against_a_dark_canvas(string $seed): void
    {
        $out = Colour::toContrast($seed, '#0d1117', 4.5, 'lighten');

        $this->assertGreaterThanOrEqual(4.5, Colour::contrast($out, '#0d1117'));
    }

    /** The point of using OKLCH: the result still looks like the colour asked for. */
    public function test_derivation_preserves_hue(): void
    {
        foreach (['#1db954', '#635bff', '#ff385c'] as $seed) {
            $out = Colour::toContrast($seed, '#eef2f7', 4.5, 'darken');

            $this->assertEqualsWithDelta(
                Colour::toOklch(Colour::fromHex($seed))[2],
                Colour::toOklch(Colour::fromHex($out))[2],
                1.0,
                "hue drifted more than a degree for {$seed} — gamut mapping is clipping channels again",
            );
        }
    }

    /** A colour that already passes must be returned untouched. */
    public function test_a_passing_seed_is_left_alone(): void
    {
        $this->assertSame('#1d4ed8', Colour::toContrast('#1d4ed8', '#eef2f7', 4.5, 'darken'));
    }

    public function test_an_unreachable_target_clamps_instead_of_looping(): void
    {
        $this->assertSame('#000000', Colour::toContrast('#808080', '#808080', 21.0, 'darken'));
    }

    public function test_a_tint_is_pale_and_keeps_its_hue(): void
    {
        $tint = Colour::tint('#1db954');

        $this->assertGreaterThan(0.9, Colour::luminance(Colour::fromHex($tint)), 'tint should be pale');
        $this->assertEqualsWithDelta(
            Colour::toOklch(Colour::fromHex('#1db954'))[2],
            Colour::toOklch(Colour::fromHex($tint))[2],
            6.0,
        );
    }

    public function test_hex_validation(): void
    {
        foreach (['#fff', '#FFFFFF', 'abc123', '#1db954'] as $ok) {
            $this->assertTrue(Colour::isHex($ok), $ok);
        }

        foreach (['', 'red', '#12', '#12345', 'rgb(1,2,3)', '#gggggg'] as $bad) {
            $this->assertFalse(Colour::isHex($bad), $bad);
        }
    }
}
