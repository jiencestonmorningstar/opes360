# Branding Module Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a company owner re-skin the entire platform — colour, surface temperature, corner shape, density, optional liquid glass — without being able to break the WCAG AA guarantee.

**Architecture:** Owner inputs are stored as a seed on `companies.branding`. A pure PHP colour engine derives every UI role from that seed in OKLCH, binary-searching lightness until each role meets its contrast floor. The derived map is emitted as CSS custom properties in a `<style>` block in the layout head; because Tailwind v4 compiles every utility to `var(--token)`, this re-skins the platform with zero component changes.

**Tech Stack:** Laravel 12, Livewire 3, Tailwind CSS v4, Pest/PHPUnit, no new Composer packages.

**Spec:** `docs/superpowers/specs/2026-08-15-branding-design.md`

---

## File Structure

| File | Responsibility |
|---|---|
| `app/Support/Colour.php` | Colour space maths only. sRGB ↔ OKLCH, WCAG luminance, contrast, `toContrast`. No Laravel. |
| `app/Support/BrandPalette.php` | Turns owner inputs into the full light+dark token map. Depends on `Colour`. |
| `app/Support/BrandDefaults.php` | The platform's own inputs and the fixed (non-brandable) tokens. |
| `database/migrations/2026_08_20_000001_add_branding_to_companies.php` | `branding` json column. |
| `app/Models/Company.php` | `branding()` accessor, `palette()`, `brandToken()` reads through palette. |
| `resources/views/components/branding/styles.blade.php` | Emits `:root{}` / `.dark{}`. |
| `resources/css/app.css` | `.glass` utility + guards. |
| `app/Livewire/Settings/Branding.php` | The screen. |
| `resources/views/livewire/settings/branding.blade.php` | Screen markup + live preview. |
| `app/Http/Controllers/Api/BrandingController.php` | API. |
| `tests/Unit/ColourTest.php` | Colour maths. |
| `tests/Feature/BrandingTest.php` | Derivation, tenancy, screen, API. |

---

### Task 1: Colour maths

**Files:**
- Create: `app/Support/Colour.php`
- Test: `tests/Unit/ColourTest.php`

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Unit;

use App\Support\Colour;
use PHPUnit\Framework\TestCase;

class ColourTest extends TestCase
{
    public function test_hex_round_trips(): void
    {
        foreach (['#1db954', '#ffffff', '#000000', '#635bff'] as $hex) {
            $this->assertSame($hex, Colour::toHex(Colour::fromHex($hex)));
        }
    }

    public function test_oklch_round_trips_within_one_step(): void
    {
        foreach (['#1db954', '#ff385c', '#4a154b', '#008060', '#eef2f7'] as $hex) {
            $back = Colour::fromOklch(Colour::toOklch(Colour::fromHex($hex)));
            foreach (Colour::fromHex($hex) as $i => $channel) {
                $this->assertEqualsWithDelta($channel, $back[$i], 1 / 255);
            }
        }
    }

    /** Published WCAG values: white/black is exactly 21:1. */
    public function test_contrast_matches_published_values(): void
    {
        $this->assertEqualsWithDelta(21.0, Colour::contrast('#ffffff', '#000000'), 0.01);
        $this->assertEqualsWithDelta(1.0, Colour::contrast('#abcdef', '#abcdef'), 0.001);
        $this->assertEqualsWithDelta(2.59, Colour::contrast('#1db954', '#ffffff'), 0.01);
        $this->assertEqualsWithDelta(4.18, Colour::contrast('#635bff', '#eef2f7'), 0.01);
    }

    public function test_contrast_is_symmetric(): void
    {
        $this->assertSame(
            Colour::contrast('#1db954', '#ffffff'),
            Colour::contrast('#ffffff', '#1db954'),
        );
    }

    public function test_darkening_reaches_the_target_against_canvas(): void
    {
        foreach (['#1db954', '#ff385c', '#635bff', '#ffff00'] as $seed) {
            $out = Colour::toContrast($seed, '#eef2f7', 4.5, 'darken');
            $this->assertGreaterThanOrEqual(4.5, Colour::contrast($out, '#eef2f7'), "{$seed} failed");
        }
    }

    public function test_lightening_reaches_the_target_against_a_dark_canvas(): void
    {
        foreach (['#1db954', '#635bff', '#4a154b'] as $seed) {
            $out = Colour::toContrast($seed, '#000000', 4.5, 'lighten');
            $this->assertGreaterThanOrEqual(4.5, Colour::contrast($out, '#000000'), "{$seed} failed");
        }
    }

    /** The point of OKLCH: the result must still look like the colour asked for. */
    public function test_derivation_preserves_hue(): void
    {
        $seed = '#1db954';
        $out = Colour::toContrast($seed, '#eef2f7', 4.5, 'darken');

        $seedHue = Colour::toOklch(Colour::fromHex($seed))[2];
        $outHue = Colour::toOklch(Colour::fromHex($out))[2];

        $this->assertEqualsWithDelta($seedHue, $outHue, 3.0, 'hue drifted more than 3 degrees');
    }

    public function test_an_unreachable_target_clamps_instead_of_looping(): void
    {
        // Nothing can reach 21:1 against mid grey. It must return the best it can.
        $out = Colour::toContrast('#808080', '#808080', 21.0, 'darken');
        $this->assertSame('#000000', $out);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --filter=ColourTest`
Expected: FAIL — `Class "App\Support\Colour" not found`

- [ ] **Step 3: Implement**

```php
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
 * produces wildly different contrast depending on hue. OKLab was built so that
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
     * @param array{float, float, float} $rgb
     */
    public static function luminance(array $rgb): float
    {
        return 0.2126 * self::toLinear($rgb[0])
             + 0.7152 * self::toLinear($rgb[1])
             + 0.0722 * self::toLinear($rgb[2]);
    }

    /**
     * WCAG contrast ratio. Symmetric — the ratio between two colours is one
     * number, whichever of the pair is the text.
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
     * sRGB to Oklab. Björn Ottosson's matrices.
     *
     * @param array{float, float, float} $rgb
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

        // Cube root, sign-preserving: the LMS values can go very slightly
        // negative for colours near the gamut edge, and a plain pow() would
        // return NAN there and silently poison the whole palette.
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
     * @param array{float, float, float} $lab
     * @return array{float, float, float} sRGB, clamped to gamut
     */
    public static function fromOklab(array $lab): array
    {
        [$L, $a, $bb] = $lab;

        $l = ($L + 0.3963377774 * $a + 0.2158037573 * $bb) ** 3;
        $m = ($L - 0.1055613458 * $a - 0.0638541728 * $bb) ** 3;
        $s = ($L - 0.0894841775 * $a - 1.2914855480 * $bb) ** 3;

        return [
            self::clamp(self::fromLinear(4.0767416621 * $l - 3.3077115913 * $m + 0.2309699292 * $s)),
            self::clamp(self::fromLinear(-1.2684380046 * $l + 2.6097574011 * $m - 0.3413193965 * $s)),
            self::clamp(self::fromLinear(-0.0041960863 * $l - 0.7034186147 * $m + 1.7076147010 * $s)),
        ];
    }

    protected static function clamp(float $c): float
    {
        return max(0.0, min(1.0, $c));
    }

    /**
     * @param array{float, float, float} $rgb
     * @return array{float, float, float} [L, C, H degrees]
     */
    public static function toOklch(array $rgb): array
    {
        [$L, $a, $b] = self::toOklab($rgb);

        $h = rad2deg(atan2($b, $a));

        return [$L, sqrt($a * $a + $b * $b), $h < 0 ? $h + 360 : $h];
    }

    /**
     * @param array{float, float, float} $lch
     * @return array{float, float, float} sRGB
     */
    public static function fromOklch(array $lch): array
    {
        [$L, $C, $H] = $lch;
        $rad = deg2rad($H);

        return self::fromOklab([$L, $C * cos($rad), $C * sin($rad)]);
    }

    /**
     * Move a seed's lightness until it meets a contrast target, holding hue and
     * chroma so the result still reads as the colour the owner chose.
     *
     * `$direction` is explicit rather than inferred from the background: at mid
     * lightness both directions can reach the target, and only the caller knows
     * whether it is building the light theme or the dark one.
     *
     * If the target is unreachable at any lightness — an extreme chroma at a
     * hue with a narrow gamut — this returns the best achievable value rather
     * than looping or throwing. Callers that need to know can re-measure.
     */
    public static function toContrast(string $seed, string $against, float $target, string $direction): string
    {
        if (self::contrast($seed, $against) >= $target) {
            return self::toHex(self::fromHex($seed));
        }

        [$L, $C, $H] = self::toOklch(self::fromHex($seed));

        // The extreme in the chosen direction. If even this cannot reach the
        // target, nothing between here and the seed can either.
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
            $candidate = self::toHex(self::fromOklch([$mid, $C, $H]));

            if (self::contrast($candidate, $against) >= $target) {
                // Keep the half nearest the seed, so we darken as little as
                // possible and stay close to the brand.
                if ($direction === 'lighten') {
                    $hi = $mid;
                } else {
                    $lo = $mid;
                }
            } else {
                if ($direction === 'lighten') {
                    $lo = $mid;
                } else {
                    $hi = $mid;
                }
            }
        }

        $result = self::toHex(self::fromOklch([$direction === 'lighten' ? $hi : $lo, $C, $H]));

        // Rounding to 8 bits can drop the ratio a hair under the target. Step
        // once more toward the extreme rather than return something that fails
        // the very guarantee this function exists to provide.
        if (self::contrast($result, $against) < $target) {
            return $extreme;
        }

        return $result;
    }

    /**
     * A pale wash of a hue: same hue, low chroma, high lightness. Used for chip
     * and icon-bubble backgrounds.
     */
    public static function tint(string $seed, float $lightness = 0.965, float $chromaScale = 0.18): string
    {
        [, $C, $H] = self::toOklch(self::fromHex($seed));

        return self::toHex(self::fromOklch([$lightness, $C * $chromaScale, $H]));
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `php artisan test --filter=ColourTest`
Expected: PASS, 8 tests

- [ ] **Step 5: Commit**

```bash
git add app/Support/Colour.php tests/Unit/ColourTest.php
git commit -m "Add the colour engine the branding module derives from"
```

---

### Task 2: Palette derivation

**Files:**
- Create: `app/Support/BrandDefaults.php`, `app/Support/BrandPalette.php`
- Test: `tests/Feature/BrandPaletteTest.php`

- [ ] **Step 1: Write the failing test — the hostile seed corpus**

This is the centre of gravity for the whole module. It is the difference between
"we generate palettes" and "we cannot generate a broken palette".

```php
<?php

namespace Tests\Feature;

use App\Support\BrandDefaults;
use App\Support\BrandPalette;
use App\Support\Colour;
use Tests\TestCase;

class BrandPaletteTest extends TestCase
{
    /** Colours chosen to break a naive generator. */
    public static function hostileSeeds(): array
    {
        return [
            'Spotify green'  => ['#1db954'],
            'Airbnb red'     => ['#ff385c'],
            'Stripe indigo'  => ['#635bff'],
            'pure yellow'    => ['#ffff00'],
            'pure cyan'      => ['#00ffff'],
            'pure black'     => ['#000000'],
            'pure white'     => ['#ffffff'],
            'near white'     => ['#fefefe'],
            'near black'     => ['#010101'],
            'mid grey'       => ['#808080'],
            'deep aubergine' => ['#4a154b'],
        ];
    }

    /** @dataProvider hostileSeeds */
    public function test_every_seed_yields_a_readable_light_palette(string $seed): void
    {
        $t = BrandPalette::derive(['primary' => $seed] + BrandDefaults::inputs())['light'];

        foreach (['canvas', 'surface', 'surface-2'] as $surface) {
            $this->assertGreaterThanOrEqual(
                4.5,
                Colour::contrast($t['--color-brand'], $t["--color-{$surface}"]),
                "brand ink on {$surface} for seed {$seed}",
            );
        }

        $this->assertGreaterThanOrEqual(
            4.5,
            Colour::contrast($t['--color-fill-brand'], '#ffffff'),
            "white text on brand fill for seed {$seed}",
        );

        $this->assertGreaterThanOrEqual(
            4.5,
            Colour::contrast($t['--color-brand'], $t['--color-tint-blue']),
            "brand ink on its own tint for seed {$seed}",
        );
    }

    /** @dataProvider hostileSeeds */
    public function test_every_seed_yields_a_readable_dark_palette(string $seed): void
    {
        $t = BrandPalette::derive(['primary' => $seed] + BrandDefaults::inputs())['dark'];

        foreach (['canvas', 'surface', 'surface-2'] as $surface) {
            $this->assertGreaterThanOrEqual(
                4.5,
                Colour::contrast($t['--color-brand'], $t["--color-{$surface}"]),
                "brand ink on dark {$surface} for seed {$seed}",
            );
        }

        $this->assertGreaterThanOrEqual(
            4.5,
            Colour::contrast($t['--color-fill-brand'], '#ffffff'),
            "white text on dark brand fill for seed {$seed}",
        );
    }

    /** @dataProvider hostileSeeds */
    public function test_the_text_ramp_survives_every_seed(string $seed): void
    {
        foreach (['light', 'dark'] as $mode) {
            $t = BrandPalette::derive(['primary' => $seed] + BrandDefaults::inputs())[$mode];

            foreach (['ink', 'ink-2', 'muted', 'faint'] as $step) {
                foreach (['canvas', 'surface', 'surface-2'] as $surface) {
                    $this->assertGreaterThanOrEqual(
                        4.5,
                        Colour::contrast($t["--color-{$step}"], $t["--color-{$surface}"]),
                        "{$step} on {$mode} {$surface} for seed {$seed}",
                    );
                }
            }
        }
    }

    public function test_semantic_colours_are_not_brandable(): void
    {
        $a = BrandPalette::derive(['primary' => '#1db954'] + BrandDefaults::inputs());
        $b = BrandPalette::derive(['primary' => '#ff385c'] + BrandDefaults::inputs());

        foreach (['positive', 'warning', 'negative'] as $role) {
            $this->assertSame(
                $a['light']["--color-{$role}"],
                $b['light']["--color-{$role}"],
                "{$role} moved with the brand — overdue must never turn green",
            );
        }
    }

    public function test_density_and_radius_land_in_the_token_map(): void
    {
        $compact = BrandPalette::derive(['density' => 'compact'] + BrandDefaults::inputs());
        $comfy = BrandPalette::derive(['density' => 'comfortable'] + BrandDefaults::inputs());

        $this->assertNotSame($compact['root']['--spacing'], $comfy['root']['--spacing']);

        $sharp = BrandPalette::derive(['radius' => 'sharp'] + BrandDefaults::inputs());
        $this->assertSame('0px', $sharp['root']['--radius-card']);
    }

    public function test_glass_tokens_appear_only_for_the_glass_skin(): void
    {
        $solid = BrandPalette::derive(['skin' => 'solid'] + BrandDefaults::inputs());
        $glass = BrandPalette::derive(['skin' => 'glass'] + BrandDefaults::inputs());

        $this->assertSame('0px', $solid['root']['--glass-blur']);
        $this->assertNotSame('0px', $glass['root']['--glass-blur']);
    }

    /** The generator must be able to reproduce a known-good design. */
    public function test_the_platform_default_reproduces_todays_palette(): void
    {
        $t = BrandPalette::derive(BrandDefaults::inputs())['light'];

        $this->assertLessThan(
            8.0,
            $this->deltaE($t['--color-brand'], '#1d4ed8'),
            'the default seed no longer derives roughly the hand-tuned brand blue',
        );
    }

    protected function deltaE(string $a, string $b): float
    {
        [$l1, $a1, $b1] = Colour::toOklab(Colour::fromHex($a));
        [$l2, $a2, $b2] = Colour::toOklab(Colour::fromHex($b));

        return sqrt(($l1 - $l2) ** 2 + ($a1 - $a2) ** 2 + ($b1 - $b2) ** 2) * 100;
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --filter=BrandPaletteTest`
Expected: FAIL — `Class "App\Support\BrandDefaults" not found`

- [ ] **Step 3: Implement `BrandDefaults`**

```php
<?php

namespace App\Support;

/**
 * The platform's own branding inputs, and the parts of the design system a
 * company cannot change.
 */
class BrandDefaults
{
    /** @return array<string, mixed> */
    public static function inputs(): array
    {
        return [
            'primary' => '#1d4ed8',
            'secondary' => '#7e22ce',
            'neutral' => 'cool',
            'radius' => 'rounded',
            'density' => 'comfortable',
            'skin' => 'solid',
            'glass_strength' => 0.5,
        ];
    }

    /**
     * Semantic colours are fixed. A business must not be able to make "overdue"
     * green or "paid" red; the meaning of these three is not a brand decision.
     *
     * @return array<string, array{light: string, dark: string, fill_light: string, fill_dark: string}>
     */
    public static function semantics(): array
    {
        return [
            'positive' => ['light' => '#166534', 'dark' => '#4ade80', 'fill_light' => '#166534', 'fill_dark' => '#15803d'],
            'warning' => ['light' => '#9a3412', 'dark' => '#fdba74', 'fill_light' => '#9a3412', 'fill_dark' => '#9a3412'],
            'negative' => ['light' => '#b91c1c', 'dark' => '#fca5a5', 'fill_light' => '#b91c1c', 'fill_dark' => '#b91c1c'],
        ];
    }

    /** Neutral ramps, coolest first. Keyed by the `neutral` input. */
    public static function neutrals(): array
    {
        return [
            'cool' => [
                'light' => ['canvas' => '#eef2f7', 'surface' => '#ffffff', 'surface-2' => '#f6f8fb', 'border' => '#e3e9f0', 'border-strong' => '#cbd5e1'],
                'dark' => ['canvas' => '#000000', 'surface' => '#0d1117', 'surface-2' => '#131922', 'border' => '#1f2733', 'border-strong' => '#2b3546'],
            ],
            'warm' => [
                'light' => ['canvas' => '#f5f2ee', 'surface' => '#ffffff', 'surface-2' => '#faf8f5', 'border' => '#eae4dc', 'border-strong' => '#d6cec2'],
                'dark' => ['canvas' => '#0b0a09', 'surface' => '#14120f', 'surface-2' => '#1c1916', 'border' => '#2a2620', 'border-strong' => '#3a352d'],
            ],
            'true-black' => [
                'light' => ['canvas' => '#f2f2f2', 'surface' => '#ffffff', 'surface-2' => '#fafafa', 'border' => '#e5e5e5', 'border-strong' => '#c9c9c9'],
                'dark' => ['canvas' => '#000000', 'surface' => '#080808', 'surface-2' => '#111111', 'border' => '#1e1e1e', 'border-strong' => '#2e2e2e'],
            ],
        ];
    }

    /** `--spacing` drives every p-*, gap-* and m-* in the app. */
    public static function densities(): array
    {
        return ['compact' => '0.22rem', 'comfortable' => '0.25rem'];
    }

    /**
     * Radius scales. Kept as whole scales rather than a single number so a
     * "pill" choice rounds buttons fully without turning cards into circles.
     */
    public static function radii(): array
    {
        return [
            'sharp' => ['sm' => '0px', 'md' => '0px', 'lg' => '0px', 'xl' => '0px', '2xl' => '0px', 'card' => '0px'],
            'soft' => ['sm' => '2px', 'md' => '4px', 'lg' => '6px', 'xl' => '8px', '2xl' => '10px', 'card' => '8px'],
            'rounded' => ['sm' => '4px', 'md' => '6px', 'lg' => '8px', 'xl' => '12px', '2xl' => '16px', 'card' => '1rem'],
            'pill' => ['sm' => '8px', 'md' => '12px', 'lg' => '16px', 'xl' => '20px', '2xl' => '26px', 'card' => '1.75rem'],
        ];
    }
}
```

- [ ] **Step 4: Implement `BrandPalette`**

```php
<?php

namespace App\Support;

use App\Models\Company;
use Illuminate\Support\Facades\Cache;

/**
 * Turns an owner's branding inputs into the full CSS token map.
 *
 * Nothing here is persisted. The inputs are the record; the palette is derived
 * and cached, so improving the derivation improves every company at once
 * instead of leaving old tenants on a stale generated palette.
 */
class BrandPalette
{
    /** Text on canvas is the binding constraint — canvas is the darkest surface. */
    protected const AA = 4.5;

    public static function for(?Company $company): array
    {
        $inputs = self::inputsFor($company);

        return Cache::remember(
            'brand-palette:'.md5(json_encode($inputs)),
            now()->addDay(),
            fn () => self::derive($inputs),
        );
    }

    /** @return array<string, mixed> */
    public static function inputsFor(?Company $company): array
    {
        $stored = $company?->branding ?? [];

        // Array union, so a company that set only a primary colour still gets
        // every other key rather than a half-built palette.
        return array_merge(BrandDefaults::inputs(), array_filter(
            is_array($stored) ? $stored : [],
            fn ($v) => $v !== null && $v !== '',
        ));
    }

    /**
     * @param  array<string, mixed>  $inputs
     * @return array{light: array<string,string>, dark: array<string,string>, root: array<string,string>}
     */
    public static function derive(array $inputs): array
    {
        $inputs = array_merge(BrandDefaults::inputs(), $inputs);

        $neutrals = BrandDefaults::neutrals()[$inputs['neutral']] ?? BrandDefaults::neutrals()['cool'];

        return [
            'light' => self::mode($inputs, $neutrals['light'], 'light'),
            'dark' => self::mode($inputs, $neutrals['dark'], 'dark'),
            'root' => self::shape($inputs),
        ];
    }

    /**
     * @param  array<string, string>  $surfaces
     * @return array<string, string>
     */
    protected static function mode(array $inputs, array $surfaces, string $mode): array
    {
        // In light mode ink darkens until it reads on canvas; in dark mode it
        // lightens. Fill is the opposite problem in both: it must stay dark
        // enough to carry white text however light the ink became.
        $inkDir = $mode === 'light' ? 'darken' : 'lighten';

        $tokens = [];

        foreach ($surfaces as $name => $hex) {
            $tokens["--color-{$name}"] = $hex;
        }

        $canvas = $surfaces['canvas'];

        $tokens += self::textRamp($surfaces, $mode);

        foreach (['brand' => $inputs['primary'], 'secondary' => $inputs['secondary']] as $role => $seed) {
            $ink = Colour::toContrast($seed, $canvas, self::AA, $inkDir);

            // Also has to clear the lightest surface. In light mode canvas is
            // the darkest and clearing it clears the rest; in dark mode canvas
            // is the *darkest* too, so surface-2 is the harder one.
            $ink = Colour::toContrast($ink, $surfaces['surface-2'], self::AA, $inkDir);

            $fill = Colour::toContrast($seed, '#ffffff', self::AA, 'darken');
            $tint = $mode === 'light'
                ? Colour::tint($seed)
                : Colour::tint($seed, 0.26, 0.32);

            // The ink must read on its own tint as well as on the surfaces.
            $ink = Colour::toContrast($ink, $tint, self::AA, $inkDir);

            $tokens["--color-{$role}"] = $ink;
            $tokens["--color-fill-{$role}"] = $fill;
            $tokens["--color-tint-{$role}"] = $tint;
        }

        // The accent families the dashboard, chips and charts use. Brand and
        // secondary take two slots; the rest are derived by rotating hue so a
        // branded chart still looks like one palette rather than a rainbow.
        $tokens += self::accents($inputs, $surfaces, $mode, $inkDir);

        foreach (BrandDefaults::semantics() as $role => $values) {
            $tokens["--color-{$role}"] = $values[$mode];
            $tokens["--color-fill-{$role}"] = $values[$mode === 'light' ? 'fill_light' : 'fill_dark'];
        }

        // Legacy aliases. The design system names tints by hue (tint-blue,
        // tint-green) and views reference those names directly, so the branded
        // equivalents have to answer to the old keys too.
        $tokens['--color-tint-blue'] = $tokens['--color-tint-brand'];
        $tokens['--color-accent-blue'] = $tokens['--color-brand'];
        $tokens['--color-fill-blue'] = $tokens['--color-fill-brand'];
        $tokens['--color-tint-red'] = $mode === 'light' ? '#fdeeee' : '#2a1416';

        return $tokens;
    }

    /**
     * Four text steps, each guaranteed on every surface. Derived from the
     * neutral ramp rather than fixed, so warm and true-black themes get text
     * that belongs to them.
     *
     * @param  array<string, string>  $surfaces
     * @return array<string, string>
     */
    protected static function textRamp(array $surfaces, string $mode): array
    {
        $canvas = $surfaces['canvas'];
        $hardest = $mode === 'light' ? $canvas : $surfaces['surface-2'];
        $dir = $mode === 'light' ? 'darken' : 'lighten';

        // Targets, strongest first. `faint` sits at the AA floor exactly; the
        // steps above it are progressively stronger so the ramp still reads as
        // a hierarchy rather than four shades of the same grey.
        $targets = ['ink' => 13.0, 'ink-2' => 8.5, 'muted' => 6.0, 'faint' => 4.6];

        [, , $hue] = Colour::toOklch(Colour::fromHex($surfaces['border-strong']));

        $out = [];

        foreach ($targets as $name => $target) {
            $seed = Colour::toHex(Colour::fromOklch([$mode === 'light' ? 0.35 : 0.75, 0.02, $hue]));
            $out["--color-{$name}"] = Colour::toContrast($seed, $hardest, $target, $dir);
        }

        return $out;
    }

    /**
     * @param  array<string, string>  $surfaces
     * @return array<string, string>
     */
    protected static function accents(array $inputs, array $surfaces, string $mode, string $inkDir): array
    {
        [, $chroma, $hue] = Colour::toOklch(Colour::fromHex($inputs['primary']));

        // A fixed chroma floor: a greyscale seed would otherwise produce five
        // identical grey accents and the dashboard would lose all its coding.
        $chroma = max($chroma, 0.11);

        $families = ['green' => 130, 'orange' => 55, 'purple' => 305, 'teal' => 190, 'pink' => 350, 'slate' => null];

        $out = [];

        foreach ($families as $name => $absoluteHue) {
            // slate stays neutral; the rest keep their semantic hue so a "green"
            // chip is still green, but take the brand's chroma so the set reads
            // as one family.
            $h = $absoluteHue ?? $hue;
            $c = $absoluteHue === null ? 0.02 : $chroma;

            $seed = Colour::toHex(Colour::fromOklch([0.55, $c, $h]));

            $ink = Colour::toContrast($seed, $surfaces['canvas'], self::AA, $inkDir);
            $ink = Colour::toContrast($ink, $surfaces['surface-2'], self::AA, $inkDir);

            $tint = $mode === 'light' ? Colour::tint($seed) : Colour::tint($seed, 0.26, 0.32);
            $ink = Colour::toContrast($ink, $tint, self::AA, $inkDir);

            $out["--color-accent-{$name}"] = $ink;
            $out["--color-fill-{$name}"] = Colour::toContrast($seed, '#ffffff', self::AA, 'darken');
            $out["--color-tint-{$name}"] = $tint;
        }

        return $out;
    }

    /** @return array<string, string> */
    protected static function shape(array $inputs): array
    {
        $radii = BrandDefaults::radii()[$inputs['radius']] ?? BrandDefaults::radii()['rounded'];
        $spacing = BrandDefaults::densities()[$inputs['density']] ?? BrandDefaults::densities()['comfortable'];

        $glass = $inputs['skin'] === 'glass';
        $strength = max(0.0, min(1.0, (float) $inputs['glass_strength']));

        return [
            '--spacing' => $spacing,
            '--radius-sm' => $radii['sm'],
            '--radius-md' => $radii['md'],
            '--radius-lg' => $radii['lg'],
            '--radius-xl' => $radii['xl'],
            '--radius-2xl' => $radii['2xl'],
            '--radius-card' => $radii['card'],
            // Solid keeps a 0px blur rather than dropping the tokens, so the
            // .glass class stays harmless when the skin is off instead of
            // needing a second code path.
            '--glass-blur' => $glass ? round(8 + 16 * $strength).'px' : '0px',
            '--glass-bg' => $glass
                ? 'rgb(255 255 255 / '.round(0.08 + 0.16 * $strength, 3).')'
                : 'var(--color-surface)',
            '--glass-border' => $glass
                ? 'rgb(255 255 255 / '.round(0.18 + 0.22 * $strength, 3).')'
                : 'var(--color-border)',
        ];
    }
}
```

- [ ] **Step 5: Run to verify it passes**

Run: `php artisan test --filter=BrandPaletteTest`
Expected: PASS. If a hostile seed fails, the generator is wrong — fix the generator, never loosen the assertion.

- [ ] **Step 6: Commit**

```bash
git add app/Support/BrandDefaults.php app/Support/BrandPalette.php tests/Feature/BrandPaletteTest.php
git commit -m "Derive a whole readable palette from one seed colour"
```

---

### Task 3: Storage

**Files:**
- Create: `database/migrations/2026_08_20_000001_add_branding_to_companies.php`
- Modify: `app/Models/Company.php`

- [ ] **Step 1: Migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Inputs only. The derived palette is never stored, so improving
            // the derivation improves every company at once.
            $table->json('branding')->nullable()->after('brand_tokens');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('branding');
        });
    }
};
```

- [ ] **Step 2: Add `branding` to the model's `$fillable` and `$casts`, and add:**

```php
    /** The derived token map for this company, cached. */
    public function palette(): array
    {
        return \App\Support\BrandPalette::for($this);
    }

    /** The owner's raw branding inputs, defaults merged in. */
    public function brandingInputs(): array
    {
        return \App\Support\BrandPalette::inputsFor($this);
    }
```

- [ ] **Step 3: Point `brandToken()` at the palette**

Modify `brandToken()` so an explicit `brand_tokens` entry still wins (a business
that pinned a print colour keeps it), falling back to the derived palette, then
the supplied default. This is what makes the loyalty and VIP cards inherit
branding without editing those views.

- [ ] **Step 4: Run migrations and the suite**

Run: `php artisan migrate` then `php artisan test --filter=BrandPaletteTest`
Expected: migration runs, tests pass

- [ ] **Step 5: Commit**

```bash
git add database/migrations app/Models/Company.php
git commit -m "Store branding inputs on the company"
```

---

### Task 4: Delivery

**Files:**
- Create: `resources/views/components/branding/styles.blade.php`
- Modify: the authenticated layout, the public layout, and the print layouts

- [ ] **Step 1: The component**

Emits `:root{…}` from `root` + `light`, then `.dark{…}` from `dark`. Values are
escaped through a hex/px/rem whitelist before being written into a `<style>`
block — the inputs come from a form, and unescaped user text inside `<style>` is
an injection vector.

- [ ] **Step 2: Include it in the layout heads, after the Vite tag** so the
overrides win the cascade.

- [ ] **Step 3: Test that Company A's palette never appears in Company B's response.**

- [ ] **Step 4: Commit**

---

### Task 5: Glass

**Files:**
- Modify: `resources/css/app.css`, sidebar/topbar/modal/sheet components

- [ ] **Step 1:** Add the `.glass` utility reading the three tokens, wrapped in
`@supports (backdrop-filter: blur(1px))` with a solid fallback, forced solid
under `@media (prefers-reduced-transparency: reduce)` and inside `@media print`.

- [ ] **Step 2:** Apply `.glass` to chrome only — sidebar, top bar, modal, bottom
sheet, command palette. Not to cards holding figures.

- [ ] **Step 3:** Test that the print stylesheet contains no `backdrop-filter`.

- [ ] **Step 4:** `npm run build`, then commit.

---

### Task 6: The screen

**Files:**
- Create: `app/Livewire/Settings/Branding.php`, `resources/views/livewire/settings/branding.blade.php`
- Modify: `app/Support/Permissions.php`, `database/seeders/RolePermissionSeeder.php`, routes, settings nav

- [ ] **Step 1:** Add a `branding.manage` permission granted to Owner and Admin.
- [ ] **Step 2:** Build the screen: seed colour inputs, neutral/radius/density/skin
selectors, glass strength slider, live preview of a real dashboard fragment, and
the computed contrast ratio beside each derived role.
- [ ] **Step 3:** Reset-to-default action.
- [ ] **Step 4:** Tests — permission gate, invalid hex rejected, save persists,
preview reflects unsaved changes.
- [ ] **Step 5:** Commit.

---

### Task 7: API

**Files:**
- Create: `app/Http/Controllers/Api/BrandingController.php`
- Modify: `routes/api.php`, `docs/API.md`, the OpenAPI exporter

- [ ] **Step 1:** `GET /api/v1/branding` returning inputs plus derived palette;
`PUT /api/v1/branding` validating inputs against the same rules as the screen.
- [ ] **Step 2:** Tests — scope enforcement, tenancy, validation, and that the
API's derived output matches the screen's.
- [ ] **Step 3:** Commit.

---

### Task 8: Platform branding

**Files:**
- Modify: the marketing layout to read a platform-scoped palette

- [ ] **Step 1:** A singleton (no `company_id`) drives the marketing site through
the same engine.
- [ ] **Step 2:** Test the landing page renders with it.
- [ ] **Step 3:** Commit.

---

### Task 9: Documentation

- [ ] **Step 1:** Document the module in `docs/API.md` and a short
`docs/branding.md` covering the token contract and how to add a new brandable
token.
- [ ] **Step 2:** Run the full suite. Expected: all green.
- [ ] **Step 3:** Commit.
