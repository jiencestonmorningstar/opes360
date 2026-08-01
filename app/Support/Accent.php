<?php

namespace App\Support;

/**
 * Maps an accent name to complete Tailwind class strings.
 *
 * The classes are written out in full rather than interpolated because Tailwind
 * scans source text — `bg-tint-{$accent}` would never be generated. This file is
 * listed as a @source in resources/css/app.css so the literals below are found.
 */
class Accent
{
    public const NAMES = ['blue', 'green', 'orange', 'purple', 'teal', 'pink', 'slate'];

    /** Pastel background behind an icon (deep, low-luminance wash in dark mode). */
    public static function tint(string $accent): string
    {
        return match ($accent) {
            'green' => 'bg-tint-green',
            'orange' => 'bg-tint-orange',
            'purple' => 'bg-tint-purple',
            'teal' => 'bg-tint-teal',
            'pink' => 'bg-tint-pink',
            'slate' => 'bg-tint-slate',
            default => 'bg-tint-blue',
        };
    }

    /**
     * Saturated fill for the solid icon tiles on the stat cards.
     *
     * The `fill-` family rather than `accent-`: these tiles carry a white glyph,
     * and in dark mode the accent role is a pale tint that a white glyph would
     * disappear into. See the token comments in resources/css/app.css.
     */
    public static function solid(string $accent): string
    {
        return match ($accent) {
            'green' => 'bg-fill-green',
            'orange' => 'bg-fill-orange',
            'purple' => 'bg-fill-purple',
            'teal' => 'bg-fill-teal',
            'pink' => 'bg-fill-pink',
            'slate' => 'bg-fill-slate',
            default => 'bg-fill-blue',
        };
    }

    /** Foreground for an icon or initials sitting on a tint. */
    public static function text(string $accent): string
    {
        return match ($accent) {
            'green' => 'text-accent-green',
            'orange' => 'text-accent-orange',
            'purple' => 'text-accent-purple',
            'teal' => 'text-accent-teal',
            'pink' => 'text-accent-pink',
            'slate' => 'text-accent-slate',
            default => 'text-accent-blue',
        };
    }

    /**
     * Deterministic accent for a list row, so the same record keeps the same
     * colour between renders, devices and sessions.
     */
    public static function forKey(string $key, int $offset = 0): string
    {
        $palette = ['blue', 'green', 'orange', 'purple', 'teal', 'pink'];

        return $palette[(crc32($key) + $offset) % count($palette)];
    }

    /** Cycles the palette by position — used where the design shows a fixed order. */
    public static function byIndex(int $index): string
    {
        $palette = ['green', 'orange', 'purple', 'blue', 'teal', 'pink'];

        return $palette[$index % count($palette)];
    }
}
