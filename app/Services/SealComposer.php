<?php

namespace App\Services;

use App\Support\SealCatalog;

/**
 * Renders one of SealCatalog's six designs to a self-contained SVG — the
 * "official seal" watermark a business can generate from its own name and
 * registration details, the way LogoComposer generates a logo. Every
 * design is drawn from primitive shapes (circles, a star polygon, simple
 * leaf/shield/compass paths) rather than any specific real seal's artwork.
 *
 * Ring text follows §"seals must look industry standard... but not copy or
 * duplicate" — a top arc for the company name, a bottom arc for whatever
 * registration line the business chooses to show (or none), both drawn
 * along invisible arc paths via <textPath>, exactly the technique a real
 * engraved seal's lettering follows.
 */
class SealComposer
{
    /**
     * @param  string  $topText  Usually the company name, upper-cased.
     * @param  string  $bottomText  Usually a registration number or "EST. 2020" — optional.
     */
    public function render(
        string $topText,
        string $bottomText,
        string $design,
        string $color = '#1d4ed8',
        int $size = 480,
    ): string {
        $design = SealCatalog::exists($design) ? $design : 'starburst';
        $config = SealCatalog::designs()[$design];

        $cx = $size / 2;
        $cy = $size / 2;
        $outerR = $size * 0.47;
        $innerR = $outerR - ($size * 0.035);
        $textR = $outerR - ($size * 0.075);

        $uid = 'seal'.substr(md5($topText.$bottomText.$design), 0, 8);

        $rings = $this->rings($cx, $cy, $outerR, $innerR, $config['rings'], $color);
        $ringTextArt = $this->ringText($cx, $cy, $textR, $topText, $bottomText, $uid, $color, $size);
        $emblem = $this->emblem($config['emblem'], $cx, $cy, $innerR * 0.55, $color, $topText);

        return <<<SVG
        <svg viewBox="0 0 {$size} {$size}" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Seal">
            <g fill="none" stroke="{$color}">
                {$rings}
            </g>
            {$ringTextArt}
            <g fill="{$color}">
                {$emblem}
            </g>
        </svg>
        SVG;
    }

    protected function rings(float $cx, float $cy, float $outerR, float $innerR, int $count, string $color): string
    {
        $out = sprintf('<circle cx="%.2F" cy="%.2F" r="%.2F" stroke-width="%.2F"/>', $cx, $cy, $outerR, $outerR * 0.012);
        $out .= sprintf('<circle cx="%.2F" cy="%.2F" r="%.2F" stroke-width="%.2F"/>', $cx, $cy, $innerR, $innerR * 0.01);

        // A third design ('sunburst') gets a middle guide ring the rays sit
        // between; every other design's $count is 1 or 2 and this no-ops.
        if ($count >= 3) {
            $midR = ($outerR + $innerR) / 2;
            $out .= sprintf('<circle cx="%.2F" cy="%.2F" r="%.2F" stroke-width="%.2F" stroke-dasharray="2 4"/>', $cx, $cy, $midR, $midR * 0.006);
        }

        return $out;
    }

    protected function ringText(float $cx, float $cy, float $r, string $top, string $bottom, string $uid, string $color, int $size): string
    {
        $top = strtoupper(trim($top));
        $bottom = strtoupper(trim($bottom));
        $fontSize = $size * 0.052;

        // Top arc: left-to-right reading over the top of the circle.
        $topPath = $this->arcPath($cx, $cy, $r, 200, 340, 1);
        // Bottom arc: drawn right-to-left along the underside so the text
        // itself still reads left-to-right rather than upside down.
        $bottomPath = $this->arcPath($cx, $cy, $r, 340, 200, 0);

        $svg = sprintf(
            '<defs><path id="%1$sTop" d="%2$s" fill="none"/><path id="%1$sBottom" d="%3$s" fill="none"/></defs>',
            $uid, $topPath, $bottomPath,
        );

        if ($top !== '') {
            $svg .= sprintf(
                '<text font-family="Georgia, \'Times New Roman\', serif" font-size="%.1F" font-weight="700" letter-spacing="2" fill="%s">'
                .'<textPath href="#%sTop" startOffset="50%%" text-anchor="middle">%s</textPath></text>',
                $fontSize, $color, $uid, e($top),
            );
        }

        if ($bottom !== '') {
            $svg .= sprintf(
                '<text font-family="Georgia, \'Times New Roman\', serif" font-size="%.1F" font-weight="600" letter-spacing="1.5" fill="%s">'
                .'<textPath href="#%sBottom" startOffset="50%%" text-anchor="middle">%s</textPath></text>',
                $fontSize * 0.82, $color, $uid, e($bottom),
            );
        }

        return $svg;
    }

    /** angle in degrees, 0 = 3 o'clock, increasing clockwise (SVG y-down convention). */
    protected function arcPath(float $cx, float $cy, float $r, float $startDeg, float $endDeg, int $sweep): string
    {
        $start = $this->pointOnCircle($cx, $cy, $r, $startDeg);
        $end = $this->pointOnCircle($cx, $cy, $r, $endDeg);

        $delta = fmod($endDeg - $startDeg + 360, 360);
        $large = $delta > 180 ? 1 : 0;

        return sprintf('M %.2F,%.2F A %.2F,%.2F 0 %d,%d %.2F,%.2F', $start[0], $start[1], $r, $r, $large, $sweep, $end[0], $end[1]);
    }

    /** @return array{0: float, 1: float} */
    protected function pointOnCircle(float $cx, float $cy, float $r, float $deg): array
    {
        $rad = deg2rad($deg);

        return [$cx + $r * cos($rad), $cy + $r * sin($rad)];
    }

    protected function emblem(string $kind, float $cx, float $cy, float $r, string $color, string $companyName): string
    {
        return match ($kind) {
            'star' => $this->star($cx, $cy, $r),
            'laurel' => $this->laurel($cx, $cy, $r, $color, $companyName),
            'shield' => $this->shield($cx, $cy, $r),
            'compass' => $this->compass($cx, $cy, $r),
            'monogram' => $this->monogram($cx, $cy, $r, $color, $companyName),
            'sunburst' => $this->sunburst($cx, $cy, $r, $color),
            default => $this->star($cx, $cy, $r),
        };
    }

    protected function star(float $cx, float $cy, float $r): string
    {
        $points = [];

        for ($i = 0; $i < 10; $i++) {
            $radius = $i % 2 === 0 ? $r : $r * 0.42;
            $angle = -90 + $i * 36;
            [$x, $y] = $this->pointOnCircle($cx, $cy, $radius, $angle);
            $points[] = sprintf('%.2F,%.2F', $x, $y);
        }

        return '<polygon points="'.implode(' ', $points).'"/>';
    }

    protected function laurel(float $cx, float $cy, float $r, string $color, string $companyName): string
    {
        // A row of simple leaf ellipses along a gentle arc on each side,
        // meeting at the bottom — a wreath drawn from primitives, not a
        // traced illustration.
        $leaves = '';

        foreach ([-1, 1] as $side) {
            for ($i = 0; $i < 5; $i++) {
                $t = $i / 4;
                $angle = $side * (30 + $t * 55);
                [$x, $y] = $this->pointOnCircle($cx, $cy + $r * 0.15, $r * (0.55 + $t * 0.5), 90 + $angle);
                $leaves .= sprintf(
                    '<ellipse cx="%.2F" cy="%.2F" rx="%.2F" ry="%.2F" transform="rotate(%.1F %.2F %.2F)"/>',
                    $x, $y, $r * 0.16, $r * 0.08, $side * (20 + $t * 40), $x, $y,
                );
            }
        }

        return $leaves.$this->monogram($cx, $cy, $r * 0.5, $color, $companyName);
    }

    protected function shield(float $cx, float $cy, float $r): string
    {
        $top = $cy - $r;
        $bottom = $cy + $r;
        $left = $cx - $r * 0.78;
        $right = $cx + $r * 0.78;
        $mid = $cy + $r * 0.15;

        $path = sprintf(
            'M %.2F,%.2F L %.2F,%.2F L %.2F,%.2F L %.2F,%.2F Q %.2F,%.2F %.2F,%.2F Q %.2F,%.2F %.2F,%.2F Z',
            $left, $top, $right, $top, $right, $mid, $cx + $r * 0.35, $mid,
            $cx, $bottom, $cx - $r * 0.35, $mid,
            $left, $mid, $left, $top,
        );

        return '<path d="'.$path.'"/>';
    }

    protected function compass(float $cx, float $cy, float $r): string
    {
        $points = [];

        // An eight-point compass rose: alternating long/short diamonds.
        for ($i = 0; $i < 8; $i++) {
            $long = $i % 2 === 0;
            $angle = $i * 45;
            $tip = $this->pointOnCircle($cx, $cy, $long ? $r : $r * 0.55, $angle);
            $left = $this->pointOnCircle($cx, $cy, $r * 0.12, $angle - 6);
            $right = $this->pointOnCircle($cx, $cy, $r * 0.12, $angle + 6);
            $points[] = sprintf('M %.2F,%.2F L %.2F,%.2F L %.2F,%.2F Z', $tip[0], $tip[1], $left[0], $left[1], $right[0], $right[1]);
        }

        return '<path d="'.implode(' ', $points).'"/>';
    }

    protected function monogram(float $cx, float $cy, float $r, string $color, string $companyName): string
    {
        $initials = $this->initials($companyName);

        return sprintf(
            '<text x="%.2F" y="%.2F" font-family="Georgia, \'Times New Roman\', serif" font-size="%.1F" '
            .'font-weight="700" text-anchor="middle" dominant-baseline="central" fill="%s">%s</text>',
            $cx, $cy, $r * 1.05, $color, e($initials),
        );
    }

    protected function sunburst(float $cx, float $cy, float $r, string $color): string
    {
        $rays = '';

        for ($i = 0; $i < 16; $i++) {
            $angle = $i * (360 / 16);
            $inner = $this->pointOnCircle($cx, $cy, $r * 0.5, $angle);
            $outer = $this->pointOnCircle($cx, $cy, $r, $angle);
            $rays .= sprintf(
                '<line x1="%.2F" y1="%.2F" x2="%.2F" y2="%.2F" stroke="%s" stroke-width="%.2F"/>',
                $inner[0], $inner[1], $outer[0], $outer[1], $color, $r * 0.05,
            );
        }

        return $rays.sprintf('<circle cx="%.2F" cy="%.2F" r="%.2F"/>', $cx, $cy, $r * 0.42);
    }

    protected function initials(string $name): string
    {
        $words = preg_split('/\s+/', trim($name)) ?: [];

        return strtoupper(collect($words)->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->implode(''));
    }
}
