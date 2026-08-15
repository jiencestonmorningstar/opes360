<?php

namespace App\Support;

/**
 * Colour space maths for the branding engine.
 *
 * Pure functions, no framework. Everything here is deliberately testable in
 * isolation, because the contrast guarantee the whole branding module rests on
 * is only as good as these conversions.
 *
 * OKLCH rather than HSL: HSL "lightness" is not perceptual. HSL 50% yellow and
 * HSL 50% blue differ by more than 4:1 in actual luminance, so an HSL ramp
 * produces wildly different contrast depending on hue. Oklab was built so that
 * equal steps look equal, which is what lets us move lightness alone and trust
 * the result.
 */
class Colour
{
    /** @return array{float, float, float} sRGB channels, 0-1 */
    public static function fromHex(string $hex): array
    {
        $hex = ltrim(trim($hex), '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        return [
            hexdec(substr($hex, 0, 2)) / 255,
            hexdec(substr($hex, 2, 2)) / 255,
            hexdec(substr($hex, 4, 2)) / 255,
        ];
    }

    /** @param array{float, float, float} $rgb */
    public static function toHex(array $rgb): string
    {
        $out = '#';

        foreach ($rgb as $channel) {
            $out .= str_pad(
                dechex((int) round(max(0.0, min(1.0, $channel)) * 255)),
                2, '0', STR_PAD_LEFT,
            );
        }

        return $out;
    }

    public static function isHex(string $value): bool
    {
        return (bool) preg_match('/^#?([0-9a-f]{3}|[0-9a-f]{6})$/i', trim($value));
    }

    protected static function toLinear(float $c): float
    {
        return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    }

    protected static function fromLinear(float $c): float
    {
        return $c <= 0.0031308 ? $c * 12.92 : 1.055 * ($c ** (1 / 2.4)) - 0.055;
    }

    /**
     * WCAG 2.1 relative luminance.
     *
     * @param  array{float, float, float}  $rgb
     */
    public static function luminance(array $rgb): float
    {
        return 0.2126 * self::toLinear($rgb[0])
             + 0.7152 * self::toLinear($rgb[1])
             + 0.0722 * self::toLinear($rgb[2]);
    }

    /**
     * WCAG contrast ratio.
     *
     * Symmetric: the ratio between two colours is one number, whichever of the
     * pair is the text. Roles are therefore distinguished not by direction but
     * by which background they must survive — a button fights white, while body
     * text has to hold on canvas, which is darker and so the binding case.
     */
    public static function contrast(string $a, string $b): float
    {
        $x = self::luminance(self::fromHex($a));
        $y = self::luminance(self::fromHex($b));

        if ($x < $y) {
            [$x, $y] = [$y, $x];
        }

        return ($x + 0.05) / ($y + 0.05);
    }

    /**
     * sRGB to Oklab, using Björn Ottosson's matrices.
     *
     * @param  array{float, float, float}  $rgb
     * @return array{float, float, float} [L, a, b]
     */
    public static function toOklab(array $rgb): array
    {
        $r = self::toLinear($rgb[0]);
        $g = self::toLinear($rgb[1]);
        $b = self::toLinear($rgb[2]);

        $l = 0.4122214708 * $r + 0.5363325363 * $g + 0.0514459929 * $b;
        $m = 0.2119034982 * $r + 0.6806995451 * $g + 0.1073969566 * $b;
        $s = 0.0883024619 * $r + 0.2817188376 * $g + 0.6299787005 * $b;

        // Sign-preserving cube root: LMS values go very slightly negative for
        // colours near the gamut edge, and a plain ** (1/3) returns NAN there,
        // which would silently poison the entire palette rather than fail loudly.
        $l = self::cbrt($l);
        $m = self::cbrt($m);
        $s = self::cbrt($s);

        return [
            0.2104542553 * $l + 0.7936177850 * $m - 0.0040720468 * $s,
            1.9779984951 * $l - 2.4285922050 * $m + 0.4505937099 * $s,
            0.0259040371 * $l + 0.7827717662 * $m - 0.8086757660 * $s,
        ];
    }

    protected static function cbrt(float $x): float
    {
        return $x < 0 ? -((-$x) ** (1 / 3)) : $x ** (1 / 3);
    }

    /**
     * @param  array{float, float, float}  $lab
     * @return array{float, float, float} sRGB, clamped into gamut
     */
    public static function fromOklab(array $lab): array
    {
        return array_map(self::clamp(...), self::rawFromOklab($lab));
    }

    /**
     * Oklab to sRGB without clamping, so callers can tell whether a colour is
     * actually inside the gamut or merely looks like it after clipping.
     *
     * @param  array{float, float, float}  $lab
     * @return array{float, float, float}
     */
    protected static function rawFromOklab(array $lab): array
    {
        [$L, $a, $bb] = $lab;

        $l = ($L + 0.3963377774 * $a + 0.2158037573 * $bb) ** 3;
        $m = ($L - 0.1055613458 * $a - 0.0638541728 * $bb) ** 3;
        $s = ($L - 0.0894841775 * $a - 1.2914855480 * $bb) ** 3;

        return [
            self::fromLinear(4.0767416621 * $l - 3.3077115913 * $m + 0.2309699292 * $s),
            self::fromLinear(-1.2684380046 * $l + 2.6097574011 * $m - 0.3413193965 * $s),
            self::fromLinear(-0.0041960863 * $l - 0.7034186147 * $m + 1.7076147010 * $s),
        ];
    }

    protected static function clamp(float $c): float
    {
        return max(0.0, min(1.0, $c));
    }

    /**
     * @param  array{float, float, float}  $rgb
     * @return array{float, float, float} [L, C, H in degrees]
     */
    public static function toOklch(array $rgb): array
    {
        [$L, $a, $b] = self::toOklab($rgb);

        $h = rad2deg(atan2($b, $a));

        return [$L, sqrt($a * $a + $b * $b), $h < 0 ? $h + 360 : $h];
    }

    /**
     * @param  array{float, float, float}  $lch
     * @return array{float, float, float} sRGB
     */
    public static function fromOklch(array $lch): array
    {
        [$L, $C, $H] = $lch;

        // Gamut-map by reducing chroma, not by clipping channels.
        //
        // A saturated green darkened toward black leaves the sRGB gamut, and
        // clipping the three channels independently drags the hue with it —
        // Spotify's green landed 4.9 degrees away, which is a visibly different
        // green from the one the owner chose. Backing the chroma off until the
        // colour fits keeps hue and lightness exactly, which is the whole
        // reason for working in OKLCH rather than HSL.
        if (self::inGamut(self::lab($L, $C, $H))) {
            return self::fromOklab(self::lab($L, $C, $H));
        }

        $lo = 0.0;
        $hi = $C;

        for ($i = 0; $i < 20; $i++) {
            $mid = ($lo + $hi) / 2;

            if (self::inGamut(self::lab($L, $mid, $H))) {
                $lo = $mid;
            } else {
                $hi = $mid;
            }
        }

        return self::fromOklab(self::lab($L, $lo, $H));
    }

    /** @return array{float, float, float} */
    protected static function lab(float $L, float $C, float $H): array
    {
        $rad = deg2rad($H);

        return [$L, $C * cos($rad), $C * sin($rad)];
    }

    /** @param array{float, float, float} $lab */
    protected static function inGamut(array $lab): bool
    {
        foreach (self::rawFromOklab($lab) as $channel) {
            // Half an 8-bit step of slack: values a hair outside round back to
            // a legal byte anyway, and refusing them would shrink the gamut for
            // no visible gain.
            if ($channel < -0.002 || $channel > 1.002) {
                return false;
            }
        }

        return true;
    }

    /**
     * Move a seed's lightness until it meets a contrast target, holding hue and
     * chroma so the result still reads as the colour the owner chose.
     *
     * $direction is explicit rather than inferred from the background: at mid
     * lightness both directions can reach the target, and only the caller knows
     * whether it is building the light theme or the dark one.
     *
     * If the target is unreachable at any lightness — an extreme chroma at a hue
     * with a narrow gamut — this returns the best achievable value rather than
     * looping or throwing. Callers that must know can re-measure the result.
     */
    public static function toContrast(string $seed, string $against, float $target, string $direction): string
    {
        if (self::contrast($seed, $against) >= $target) {
            return self::toHex(self::fromHex($seed));
        }

        [$L, $C, $H] = self::toOklch(self::fromHex($seed));

        // The extreme in the chosen direction. If even this cannot reach the
        // target, nothing between it and the seed can either.
        $limit = $direction === 'lighten' ? 1.0 : 0.0;
        $extreme = self::toHex(self::fromOklch([$limit, $C, $H]));

        if (self::contrast($extreme, $against) < $target) {
            return $extreme;
        }

        // Binary search on lightness. 24 iterations takes the interval well
        // below 8-bit precision, so this converges to the closest representable
        // colour rather than merely near it.
        $lo = min($L, $limit);
        $hi = max($L, $limit);

        for ($i = 0; $i < 24; $i++) {
            $mid = ($lo + $hi) / 2;
            $passes = self::contrast(self::toHex(self::fromOklch([$mid, $C, $H])), $against) >= $target;

            // Keep the half nearest the seed on a pass, so we move as little as
            // possible and stay close to the brand.
            if ($direction === 'lighten') {
                $passes ? $hi = $mid : $lo = $mid;
            } else {
                $passes ? $lo = $mid : $hi = $mid;
            }
        }

        $result = self::toHex(self::fromOklch([$direction === 'lighten' ? $hi : $lo, $C, $H]));

        // Rounding to 8 bits can drop the ratio a hair under the target. Fall
        // back to the extreme rather than return something that fails the very
        // guarantee this function exists to provide.
        return self::contrast($result, $against) >= $target ? $result : $extreme;
    }

    /**
     * A pale wash of a hue: same hue, low chroma, high lightness. Used behind
     * chips and icon bubbles.
     */
    public static function tint(string $seed, float $lightness = 0.965, float $chromaScale = 0.18): string
    {
        [, $C, $H] = self::toOklch(self::fromHex($seed));

        return self::toHex(self::fromOklch([$lightness, $C * $chromaScale, $H]));
    }
}
