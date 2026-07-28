<?php

namespace App\Services;

use Illuminate\Support\Str;

/**
 * Parametric SVG logo composer (Module 1).
 *
 * Logos are composed from templates rather than generated as pixels, because
 * the spec requires SVG export and transparent backgrounds at print resolution.
 * A diffusion model returns a PNG with artefacts, inconsistent stroke weights
 * and no clean vector path; auto-tracing that produces bloated, unusable SVG.
 * A composer produces genuinely clean vectors, is deterministic, is instant,
 * and cannot emit trademark-infringing or offensive output.
 * See docs/architecture/decisions.md #5.
 *
 * The AI layer's job (Phase 11) is to *choose* between these configurations —
 * palette, mark, layout, typeface — not to draw.
 */
class LogoComposer
{
    /** Industry-tuned palettes: [primary, secondary]. */
    public const PALETTES = [
        'ocean' => ['#2563eb', '#0ea5e9'],
        'forest' => ['#15803d', '#4ade80'],
        'sunset' => ['#ea580c', '#f59e0b'],
        'royal' => ['#7c3aed', '#c084fc'],
        'slate' => ['#0f172a', '#64748b'],
        'rose' => ['#be123c', '#fb7185'],
        'teal' => ['#0d9488', '#2dd4bf'],
        'gold' => ['#a16207', '#facc15'],
    ];

    /** Geometric marks, drawn in a 100×100 box so layouts can place them freely. */
    public const MARKS = [
        'orbit', 'shield', 'hexagon', 'spark', 'arc', 'blocks', 'leaf', 'wave',
    ];

    public const LAYOUTS = ['horizontal', 'stacked', 'badge'];

    /** Which palettes and marks suit which industry, used as the default offer. */
    public const INDUSTRY_HINTS = [
        'Technology' => ['ocean', 'orbit'],
        'Healthcare' => ['teal', 'shield'],
        'Construction' => ['sunset', 'blocks'],
        'Retail' => ['rose', 'spark'],
        'Agriculture' => ['forest', 'leaf'],
        'Hospitality' => ['gold', 'arc'],
        'Education' => ['royal', 'hexagon'],
        'Fashion' => ['rose', 'spark'],
        'Manufacturing' => ['slate', 'blocks'],
        'Consulting' => ['ocean', 'hexagon'],
    ];

    /**
     * Renders a complete, standalone SVG.
     *
     * @param  array{palette?: string, mark?: string, layout?: string, initials?: string}  $options
     */
    public function render(string $name, string $tagline = '', array $options = [], int $width = 640): string
    {
        $palette = self::PALETTES[$options['palette'] ?? 'ocean'] ?? self::PALETTES['ocean'];
        $mark = in_array($options['mark'] ?? '', self::MARKS, true) ? $options['mark'] : 'orbit';
        $layout = in_array($options['layout'] ?? '', self::LAYOUTS, true) ? $options['layout'] : 'horizontal';

        [$primary, $secondary] = $palette;
        $initials = $options['initials'] ?? $this->initials($name);
        $id = 'g'.substr(md5($name.$mark.$layout), 0, 8);

        $glyph = $this->markPath($mark, $primary, $secondary, $id);

        return match ($layout) {
            'stacked' => $this->stacked($name, $tagline, $glyph, $primary, $secondary, $id, $width),
            'badge' => $this->badge($name, $tagline, $initials, $primary, $secondary, $id, $width),
            default => $this->horizontal($name, $tagline, $glyph, $primary, $secondary, $id, $width),
        };
    }

    public function initials(string $name): string
    {
        $words = preg_split('/\s+/', trim($name)) ?: [];

        return Str::upper(collect($words)->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->implode('')) ?: 'O';
    }

    /** @return array<int, array{palette: string, mark: string, layout: string}> */
    public function suggestions(string $industry, int $count = 6): array
    {
        [$palette, $mark] = self::INDUSTRY_HINTS[$industry] ?? ['ocean', 'orbit'];

        $palettes = collect(array_keys(self::PALETTES))->sortBy(fn ($p) => $p === $palette ? 0 : 1)->values();
        $marks = collect(self::MARKS)->sortBy(fn ($m) => $m === $mark ? 0 : 1)->values();

        return collect(range(0, $count - 1))
            ->map(fn (int $i) => [
                'palette' => $palettes[$i % $palettes->count()],
                'mark' => $marks[$i % $marks->count()],
                'layout' => self::LAYOUTS[intdiv($i, 3) % count(self::LAYOUTS)],
            ])
            ->all();
    }

    protected function gradient(string $id, string $primary, string $secondary): string
    {
        return <<<SVG
        <defs><linearGradient id="{$id}" x1="0" y1="0" x2="1" y2="1">
            <stop offset="0%" stop-color="{$primary}"/><stop offset="100%" stop-color="{$secondary}"/>
        </linearGradient></defs>
        SVG;
    }

    /** Each mark is drawn inside a 100×100 box. */
    protected function markPath(string $mark, string $primary, string $secondary, string $id): string
    {
        $fill = "url(#{$id})";

        return match ($mark) {
            'shield' => '<path d="M50 6 92 22v34c0 22-18 33-42 44C26 89 8 78 8 56V22z" fill="'.$fill.'"/>'
                .'<path d="M50 30 68 40v18c0 11-9 17-18 22-9-5-18-11-18-22V40z" fill="#fff" opacity=".9"/>',
            'hexagon' => '<path d="M50 4 91 27v46L50 96 9 73V27z" fill="'.$fill.'"/>'
                .'<path d="M50 28 72 40v24L50 76 28 64V40z" fill="#fff" opacity=".92"/>',
            'spark' => '<path d="M50 4 62 38l34 12-34 12-12 34-12-34L4 50l34-12z" fill="'.$fill.'"/>',
            'arc' => '<path d="M8 78a42 42 0 0 1 84 0" fill="none" stroke="'.$fill.'" stroke-width="16" stroke-linecap="round"/>'
                .'<circle cx="50" cy="78" r="11" fill="'.$secondary.'"/>',
            'blocks' => '<rect x="8" y="8" width="38" height="38" rx="7" fill="'.$primary.'"/>'
                .'<rect x="54" y="8" width="38" height="38" rx="7" fill="'.$secondary.'"/>'
                .'<rect x="8" y="54" width="38" height="38" rx="7" fill="'.$secondary.'"/>'
                .'<rect x="54" y="54" width="38" height="38" rx="7" fill="'.$primary.'"/>',
            'leaf' => '<path d="M86 14C50 14 14 32 14 68c0 10 4 18 4 18s10-40 68-56c0 0-24 30-56 44 0 0 56 8 56-60z" fill="'.$fill.'"/>',
            'wave' => '<path d="M6 62c14-22 26-22 40 0s26 22 44-4" fill="none" stroke="'.$fill.'" stroke-width="15" stroke-linecap="round"/>'
                .'<path d="M6 34c14-22 26-22 40 0" fill="none" stroke="'.$secondary.'" stroke-width="10" stroke-linecap="round" opacity=".7"/>',
            default => '<circle cx="50" cy="50" r="42" fill="none" stroke="'.$fill.'" stroke-width="13"/>'
                .'<circle cx="50" cy="50" r="16" fill="'.$secondary.'"/>'
                .'<circle cx="88" cy="30" r="9" fill="'.$primary.'"/>',
        };
    }

    protected function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /**
     * Approximate rendered width of a bold string, in user units.
     *
     * SVG cannot measure text server-side, so the canvas is sized from an
     * estimate. Without this the viewBox is fixed and a long business name is
     * simply clipped — which is most of them, not an edge case.
     */
    protected function textWidth(string $text, float $fontSize): float
    {
        return mb_strlen($text) * $fontSize * 0.58;
    }

    protected function horizontal(string $name, string $tagline, string $glyph, string $p, string $s, string $id, int $w): string
    {
        $safeName = $this->escape($name);
        $safeTag = $this->escape($tagline);

        $textX = 215;
        $nameSize = 52;
        $tagSize = 30;

        // The canvas grows to fit whichever line is longer, with a right margin.
        $viewWidth = (int) max(
            640,
            $textX + max($this->textWidth($name, $nameSize), $this->textWidth($tagline, $tagSize)) + 40
        );

        $h = (int) round($w * (180 / $viewWidth));

        $taglineEl = $tagline
            ? '<text x="'.$textX.'" y="120" font-family="Inter,Arial,sans-serif" font-size="'.$tagSize.'" fill="#64748b">'.$safeTag.'</text>'
            : '';
        $nameY = $tagline ? 78 : 96;

        return <<<SVG
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {$viewWidth} 180" width="{$w}" height="{$h}" role="img" aria-label="{$safeName}">
        {$this->gradient($id, $p, $s)}
        <g transform="translate(30,40)">{$glyph}</g>
        <text x="{$textX}" y="{$nameY}" font-family="Inter,Arial,sans-serif" font-size="{$nameSize}" font-weight="800" letter-spacing="-1.5" fill="#0f172a">{$safeName}</text>
        {$taglineEl}
        </svg>
        SVG;
    }

    protected function stacked(string $name, string $tagline, string $glyph, string $p, string $s, string $id, int $w): string
    {
        $safeName = $this->escape($name);
        $safeTag = $this->escape($tagline);

        $nameSize = 46;
        $viewWidth = (int) max(440, $this->textWidth($name, $nameSize) + 80);
        $centre = (int) round($viewWidth / 2);
        $glyphX = $centre - 50;
        $h = (int) round($w * (340 / $viewWidth));

        $taglineEl = $tagline
            ? '<text x="'.$centre.'" y="300" text-anchor="middle" font-family="Inter,Arial,sans-serif" font-size="26" fill="#64748b">'.$safeTag.'</text>'
            : '';

        return <<<SVG
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {$viewWidth} 340" width="{$w}" height="{$h}" role="img" aria-label="{$safeName}">
        {$this->gradient($id, $p, $s)}
        <g transform="translate({$glyphX},30)">{$glyph}</g>
        <text x="{$centre}" y="245" text-anchor="middle" font-family="Inter,Arial,sans-serif" font-size="{$nameSize}" font-weight="800" letter-spacing="-1.2" fill="#0f172a">{$safeName}</text>
        {$taglineEl}
        </svg>
        SVG;
    }

    protected function badge(string $name, string $tagline, string $initials, string $p, string $s, string $id, int $w): string
    {
        $safeName = $this->escape($name);
        $safeTag = $this->escape($tagline);
        $safeInitials = $this->escape($initials);

        $nameSize = 42;
        $viewWidth = (int) max(440, $this->textWidth($name, $nameSize) + 80);
        $centre = (int) round($viewWidth / 2);
        $h = (int) round($w * (350 / $viewWidth));

        $taglineEl = $tagline
            ? '<text x="'.$centre.'" y="318" text-anchor="middle" font-family="Inter,Arial,sans-serif" font-size="24" fill="#64748b">'.$safeTag.'</text>'
            : '';

        return <<<SVG
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {$viewWidth} 350" width="{$w}" height="{$h}" role="img" aria-label="{$safeName}">
        {$this->gradient($id, $p, $s)}
        <circle cx="{$centre}" cy="120" r="96" fill="url(#{$id})"/>
        <circle cx="{$centre}" cy="120" r="80" fill="none" stroke="#fff" stroke-width="4" opacity=".55"/>
        <text x="{$centre}" y="150" text-anchor="middle" font-family="Inter,Arial,sans-serif" font-size="76" font-weight="800" fill="#fff">{$safeInitials}</text>
        <text x="{$centre}" y="272" text-anchor="middle" font-family="Inter,Arial,sans-serif" font-size="{$nameSize}" font-weight="800" letter-spacing="-1" fill="#0f172a">{$safeName}</text>
        {$taglineEl}
        </svg>
        SVG;
    }
}
