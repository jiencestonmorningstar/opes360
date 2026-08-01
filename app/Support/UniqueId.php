<?php

namespace App\Support;

use RuntimeException;

/**
 * A random identifier that is not already taken.
 *
 * Every column these feed carries a unique index, so a collision is not a
 * harmless duplicate — it is a QueryException raised inside a transaction that
 * was issuing an invoice or selling somebody's tickets, which takes the whole
 * order down with it. The odds are small and shrink with the length of the
 * identifier; the retry costs one indexed lookup. Losing a sale to a birthday
 * collision is the worse trade.
 *
 * The slug and loyalty-card generators already worked this way. This is the
 * same idea, in one place, for the identifiers that were still trusting luck.
 */
class UniqueId
{
    /**
     * @param  callable(): string  $generate  Produces a fresh candidate.
     * @param  callable(string): bool  $taken  True when the candidate already exists.
     */
    public static function make(callable $generate, callable $taken, int $attempts = 8): string
    {
        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            $candidate = $generate();

            if (! $taken($candidate)) {
                return $candidate;
            }
        }

        // Never expected. If it happens the generator has far less entropy than
        // it should, and failing loudly beats writing a duplicate.
        throw new RuntimeException("Could not generate a unique identifier after {$attempts} attempts.");
    }
}
