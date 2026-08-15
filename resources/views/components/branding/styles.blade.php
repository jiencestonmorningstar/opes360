@php
    use App\Support\BrandPalette;
    use App\Support\CurrentCompany;

    /*
     * The company's palette, as CSS custom properties.
     *
     * Tailwind v4 compiles every utility to a variable reference —
     * .bg-fill-brand is background-color: var(--color-fill-brand), .p-3 is
     * calc(var(--spacing) * 3) — so redefining those variables on :root
     * re-skins the entire platform without a single component being edited.
     *
     * Emitted inline rather than fetched, for two reasons: a linked stylesheet
     * would be a second request the offline shell has to cache and invalidate,
     * and anything applied after first paint flashes the default blue before
     * the company's own colour arrives.
     *
     * $palette may be passed explicitly (the marketing site does this, since it
     * has no current company); otherwise it comes from whoever is signed in.
     */
    $tokens = $palette ?? BrandPalette::for(app(CurrentCompany::class)->get());

    /**
     * Values are whitelisted, not escaped.
     *
     * These come from a settings form, and Blade's escaping does nothing useful
     * inside a <style> block — a value containing a brace could close the rule
     * and open another. Rather than sanitise, only emit values that match the
     * shapes a token is allowed to be: a hex colour, a length, an rgb() with
     * alpha, or a var() reference. Anything else is dropped, which costs one
     * token rather than the integrity of the page.
     */
    $safe = static function ($value): ?string {
        $value = trim((string) $value);

        $allowed = '/^(#[0-9a-f]{3,8}'
            .'|-?[\d.]+(px|rem|em|%)?'
            .'|rgb\(\s*[\d.]+\s+[\d.]+\s+[\d.]+\s*(\/\s*[\d.]+\s*)?\)'
            .'|var\(--[a-z0-9-]+\))$/i';

        return preg_match($allowed, $value) ? $value : null;
    };

    $block = static function (array $map) use ($safe): string {
        $out = '';

        foreach ($map as $name => $value) {
            if (! preg_match('/^--[a-z0-9-]+$/i', (string) $name)) {
                continue;
            }

            if (($clean = $safe($value)) !== null) {
                $out .= $name.':'.$clean.';';
            }
        }

        return $out;
    };

    $root = $block(($tokens['root'] ?? []) + ($tokens['light'] ?? []));
    $dark = $block($tokens['dark'] ?? []);
@endphp
<style id="opes-branding">:root{{ '{' }}{!! $root !!}{{ '}' }}.dark{{ '{' }}{!! $dark !!}{{ '}' }}</style>
