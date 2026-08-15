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
     * Semantic colours are fixed.
     *
     * A business must not be able to make "overdue" green or "paid" red. What
     * these three mean is not a brand decision, and a bookkeeper moving between
     * two companies on this platform has to be able to trust them.
     *
     * @return array<string, array<string, string>>
     */
    public static function semantics(): array
    {
        return [
            'positive' => ['light' => '#166534', 'dark' => '#4ade80', 'fill_light' => '#166534', 'fill_dark' => '#15803d'],
            'warning' => ['light' => '#9a3412', 'dark' => '#fdba74', 'fill_light' => '#9a3412', 'fill_dark' => '#9a3412'],
            'negative' => ['light' => '#b91c1c', 'dark' => '#fca5a5', 'fill_light' => '#b91c1c', 'fill_dark' => '#b91c1c'],
        ];
    }

    /**
     * Neutral ramps. The surfaces are hand-picked rather than derived: they
     * carry every screen in the product, and the cool set below is the one the
     * existing design was tuned against.
     *
     * @return array<string, array<string, array<string, string>>>
     */
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

    /**
     * `--spacing` drives every p-*, gap-* and m-* in the app, so this is the
     * whole density control. The range is deliberately narrow: one variable
     * moving every gap at once will break a layout somewhere no test covers,
     * and a free slider would guarantee it.
     *
     * @return array<string, string>
     */
    public static function densities(): array
    {
        return ['compact' => '0.22rem', 'comfortable' => '0.25rem'];
    }

    /**
     * Whole radius scales rather than one number, so "pill" rounds buttons
     * fully without turning cards into circles.
     *
     * @return array<string, array<string, string>>
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

    /** @return array<int, string> */
    public static function neutralNames(): array
    {
        return array_keys(self::neutrals());
    }

    /** @return array<int, string> */
    public static function radiusNames(): array
    {
        return array_keys(self::radii());
    }

    /** @return array<int, string> */
    public static function densityNames(): array
    {
        return array_keys(self::densities());
    }

    /** @return array<int, string> */
    public static function skinNames(): array
    {
        return ['solid', 'glass'];
    }
}
