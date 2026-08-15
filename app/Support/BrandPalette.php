<?php

namespace App\Support;

use App\Models\Company;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\Cache;

/**
 * Turns an owner's branding inputs into the full CSS token map.
 *
 * Nothing derived is persisted. The inputs are the record and the palette is
 * recomputed from them, so improving the derivation improves every company at
 * once rather than leaving existing tenants on a stale generated palette.
 *
 * The contract every derived palette must honour is the same one
 * tests/Feature/Design/ColourContrastTest.php already enforces for the
 * hand-tuned default: every text role clears WCAG AA on every surface, every
 * fill carries white text, and every ink reads on its own tint. That is what
 * makes it safe to hand an owner a colour picker.
 */
class BrandPalette
{
    /**
     * The AA floor for normal text. Body text on canvas is the binding case,
     * because canvas is the darkest surface text ever sits on in light mode.
     */
    protected const AA = 4.5;

    public static function for(?Company $company): array
    {
        $inputs = self::inputsFor($company);

        return Cache::remember(
            // Keyed on the inputs themselves, so saving an edit invalidates
            // without anyone having to remember to flush.
            'brand-palette:'.md5((string) json_encode($inputs)),
            now()->addDay(),
            fn () => self::derive($inputs),
        );
    }

    /**
     * `glass` when the company has chosen the glass skin, otherwise an empty
     * string.
     *
     * Chrome calls this instead of always carrying the class and letting the
     * tokens neutralise it. `.glass` and `bg-surface` are both utilities of the
     * same specificity, so which one won would depend on their order in the
     * compiled sheet rather than on the class attribute — and in solid mode the
     * requirement is that nothing changes at all.
     */
    public static function skinClass(?Company $company = null): string
    {
        $company ??= app(CurrentCompany::class)->get();

        return self::inputsFor($company)['skin'] === 'glass' ? 'glass' : '';
    }

    /** @return array<string, mixed> */
    public static function inputsFor(?Company $company): array
    {
        $stored = $company?->branding;

        // Defaults underneath, so a company that set only a primary colour
        // still gets a complete palette rather than a half-built one.
        return array_merge(BrandDefaults::inputs(), array_filter(
            is_array($stored) ? $stored : [],
            fn ($v) => $v !== null && $v !== '',
        ));
    }

    /**
     * @param  array<string, mixed>  $inputs
     * @return array{light: array<string, string>, dark: array<string, string>, root: array<string, string>}
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
     * @param  array<string, mixed>  $inputs
     * @param  array<string, string>  $surfaces
     * @return array<string, string>
     */
    protected static function mode(array $inputs, array $surfaces, string $mode): array
    {
        $light = $mode === 'light';

        // Ink darkens in light mode and lightens in dark mode. Fill is the same
        // problem in both: it must stay dark enough to carry white text however
        // light the ink beside it became.
        $inkDir = $light ? 'darken' : 'lighten';

        $tokens = [];

        foreach ($surfaces as $name => $hex) {
            $tokens["--color-{$name}"] = $hex;
        }

        $tokens += self::textRamp($surfaces, $mode);

        foreach (['brand' => $inputs['primary'], 'secondary' => $inputs['secondary']] as $role => $seed) {
            $seed = Colour::isHex((string) $seed) ? (string) $seed : BrandDefaults::inputs()['primary'];

            $tint = $light ? Colour::tint($seed) : Colour::tint($seed, 0.26, 0.32);

            $tokens["--color-{$role}"] = self::ink($seed, $surfaces, $tint, $inkDir);
            $tokens["--color-fill-{$role}"] = Colour::toContrast($seed, '#ffffff', self::AA, 'darken');
            $tokens["--color-tint-{$role}"] = $tint;
        }

        $tokens += self::accents($inputs, $surfaces, $mode, $inkDir);

        foreach (BrandDefaults::semantics() as $role => $values) {
            $tokens["--color-{$role}"] = $values[$mode];
            $tokens["--color-fill-{$role}"] = $values[$light ? 'fill_light' : 'fill_dark'];
        }

        // Aliases. The design system names accents by hue — tint-blue,
        // accent-blue, fill-blue — and 2453 view references use those names
        // directly, so the branded equivalents have to answer to them too.
        $tokens['--color-accent-blue'] = $tokens['--color-brand'];
        $tokens['--color-fill-blue'] = $tokens['--color-fill-brand'];
        $tokens['--color-tint-blue'] = $tokens['--color-tint-brand'];
        $tokens['--color-tint-red'] = $light ? '#fdeeee' : '#2a1416';

        $tokens += self::sidebar($inputs, $tokens['--color-fill-brand']);

        return $tokens;
    }

    /**
     * The navigation rail wears the brand colour.
     *
     * It uses the **fill** role rather than the ink role, which is the whole
     * reason this is safe: fill is the value guaranteed to carry white text, so
     * whatever colour a business picks, the menu labels on it stay legible.
     * Painting the rail with a raw seed would put white text on Spotify green
     * at 2.6:1.
     *
     * Under the glass skin the rail becomes a translucent version of the same
     * colour rather than translucent white — "glass over their existing colour"
     * rather than glass instead of it.
     *
     * @param  array<string, mixed>  $inputs
     * @return array<string, string>
     */
    protected static function sidebar(array $inputs, string $fill): array
    {
        if ($inputs['skin'] !== 'glass') {
            return ['--sidebar-bg' => $fill];
        }

        $strength = max(0.0, min(1.0, (float) $inputs['glass_strength']));

        // More strength, more transparency. The floor keeps enough of the brand
        // for the rail to still read as the business's colour rather than as a
        // smudge of whatever is scrolling behind it.
        $alpha = round(0.85 - 0.3 * $strength, 3);

        $rgb = array_map(fn (float $c) => (int) round($c * 255), Colour::fromHex($fill));

        return ['--sidebar-bg' => 'rgb('.implode(' ', $rgb).' / '.$alpha.')'];
    }

    /**
     * An ink colour that reads on every surface AND on its own tint.
     *
     * Applied in sequence: each pass returns the input untouched when it
     * already clears the target, and moving further in one direction only ever
     * increases contrast against the backgrounds already cleared — so the last
     * result satisfies all of them, not just the last one checked.
     *
     * @param  array<string, string>  $surfaces
     */
    protected static function ink(string $seed, array $surfaces, string $tint, string $direction): string
    {
        $ink = $seed;

        foreach ([$surfaces['canvas'], $surfaces['surface'], $surfaces['surface-2'], $tint] as $background) {
            $ink = Colour::toContrast($ink, $background, self::AA, $direction);
        }

        return $ink;
    }

    /**
     * Four text steps, each guaranteed on every surface.
     *
     * Derived from the neutral ramp rather than fixed, so the warm and
     * true-black themes get text that belongs to them instead of borrowing the
     * cool theme's slate.
     *
     * @param  array<string, string>  $surfaces
     * @return array<string, string>
     */
    protected static function textRamp(array $surfaces, string $mode): array
    {
        $light = $mode === 'light';

        // The hardest surface to read on: the one closest in lightness to the
        // text. In light mode that is canvas, the darkest; in dark mode it is
        // surface-2, the lightest.
        $hardest = $light ? $surfaces['canvas'] : $surfaces['surface-2'];
        $direction = $light ? 'darken' : 'lighten';

        // Targets, strongest first. `faint` sits just above the AA floor; the
        // steps above it are progressively stronger so the ramp still reads as
        // a hierarchy rather than four shades of one grey.
        $targets = ['ink' => 13.0, 'ink-2' => 8.5, 'muted' => 6.0, 'faint' => 4.6];

        // Borrow the neutral's own hue so warm greys stay warm.
        [, , $hue] = Colour::toOklch(Colour::fromHex($surfaces['border-strong']));

        $seed = Colour::toHex(Colour::fromOklch([$light ? 0.35 : 0.75, 0.02, $hue]));

        $out = [];

        foreach ($targets as $name => $target) {
            $out["--color-{$name}"] = Colour::toContrast($seed, $hardest, $target, $direction);
        }

        return $out;
    }

    /**
     * The accent families the dashboard, chips and charts use.
     *
     * Each keeps its own hue — a "green" chip must still look green — but takes
     * the brand's chroma, so a branded dashboard reads as one family rather
     * than a rainbow bolted onto someone's logo.
     *
     * @param  array<string, mixed>  $inputs
     * @param  array<string, string>  $surfaces
     * @return array<string, string>
     */
    protected static function accents(array $inputs, array $surfaces, string $mode, string $inkDir): array
    {
        $primary = Colour::isHex((string) $inputs['primary'])
            ? (string) $inputs['primary']
            : BrandDefaults::inputs()['primary'];

        [, $chroma] = Colour::toOklch(Colour::fromHex($primary));

        // A chroma floor: a greyscale seed would otherwise produce six identical
        // grey accents and the dashboard would lose all of its colour coding.
        $chroma = max($chroma, 0.11);

        $families = ['green' => 148.0, 'orange' => 55.0, 'purple' => 305.0, 'teal' => 190.0, 'pink' => 358.0, 'slate' => null];

        $light = $mode === 'light';
        $out = [];

        foreach ($families as $name => $hue) {
            // Slate stays neutral; it is the "no particular meaning" accent.
            $c = $hue === null ? 0.02 : $chroma;
            $h = $hue ?? 250.0;

            $seed = Colour::toHex(Colour::fromOklch([0.55, $c, $h]));
            $tint = $light ? Colour::tint($seed) : Colour::tint($seed, 0.26, 0.32);

            $out["--color-accent-{$name}"] = self::ink($seed, $surfaces, $tint, $inkDir);
            $out["--color-fill-{$name}"] = Colour::toContrast($seed, '#ffffff', self::AA, 'darken');
            $out["--color-tint-{$name}"] = $tint;
        }

        return $out;
    }

    /**
     * Shape, density and glass. These sit on :root rather than per-theme —
     * corner radius does not change between light and dark.
     *
     * @param  array<string, mixed>  $inputs
     * @return array<string, string>
     */
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
            // Solid keeps the tokens at a zero blur rather than dropping them,
            // so the .glass class stays harmless when the skin is off instead
            // of needing a second code path in every component that uses it.
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
