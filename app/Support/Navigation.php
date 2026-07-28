<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Permission-aware reads of the navigation config.
 *
 * The routes enforce the same abilities, so this is presentation only — but a
 * menu that offers a link the user will be refused is a bug in its own right,
 * and filtering in one place keeps the sidebar, the bottom bar and the
 * dashboard's quick actions from drifting apart.
 */
class Navigation
{
    /** @return Collection<int, array<string, mixed>> */
    public static function items(): Collection
    {
        return self::permitted(config('opes.navigation'));
    }

    /** @return Collection<int, array<string, mixed>> */
    public static function primary(int $limit = 4): Collection
    {
        return self::items()->where('primary', true)->take($limit)->values();
    }

    /** @return Collection<int, array<string, mixed>> */
    public static function quickActions(): Collection
    {
        return self::permitted(config('opes.quick_actions'));
    }

    /**
     * @param  iterable<int, array<string, mixed>>  $entries
     * @return Collection<int, array<string, mixed>>
     */
    protected static function permitted(iterable $entries): Collection
    {
        return collect($entries)
            ->filter(fn (array $entry) => ! isset($entry['ability']) || Gate::allows($entry['ability']))
            ->values();
    }
}
