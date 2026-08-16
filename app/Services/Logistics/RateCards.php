<?php

namespace App\Services\Logistics;

use App\Models\FreightRate;
use App\Models\User;
use RuntimeException;

/**
 * Freight priced off a card instead of typed from memory.
 *
 * The card proposes; the person decides. quote() returns the number the
 * booking form prefills — per-kg times the weight, floored at the route's
 * minimum — and the clerk may overtype it, because freight in this market is
 * negotiated cargo by cargo. A card that dictated the price would simply be
 * routed around with a creative route name, and then the table would be a
 * lie as well as a nuisance.
 */
class RateCards
{
    /**
     * The proposed freight for a route and weight, or null when no card
     * covers the route. No weight (or zero) quotes the minimum: the smallest
     * job the business will send a truck for still costs the floor.
     */
    public function quote(string $from, string $to, float|int|string|null $weightKg = null): ?float
    {
        $rate = $this->find($from, $to);

        if ($rate === null) {
            return null;
        }

        $weight = (float) ($weightKg ?? 0);

        return round(max($weight * (float) $rate->per_kg, (float) $rate->minimum), 2);
    }

    /** The card for a route, matched case-insensitively — "douala" is Douala. */
    public function find(string $from, string $to): ?FreightRate
    {
        $from = trim($from);
        $to = trim($to);

        if ($from === '' || $to === '') {
            return null;
        }

        return FreightRate::query()
            ->whereRaw('lower(from_location) = ?', [mb_strtolower($from)])
            ->whereRaw('lower(to_location) = ?', [mb_strtolower($to)])
            ->first();
    }

    /**
     * Write (or overwrite) the one card for a route. updateOrCreate against
     * the same pair the unique index guards, so saving a route twice edits
     * the card rather than dying on the index.
     *
     * @param  array{from_location: string, to_location: string, per_kg: float|string, minimum: float|string}  $attributes
     */
    public function put(array $attributes, User $by): FreightRate
    {
        $from = trim((string) $attributes['from_location']);
        $to = trim((string) $attributes['to_location']);

        if ($from === '' || $to === '') {
            throw new RuntimeException('A rate card needs both ends of the route.');
        }

        if ((float) $attributes['per_kg'] < 0 || (float) $attributes['minimum'] < 0) {
            throw new RuntimeException('A rate cannot be negative.');
        }

        return FreightRate::query()->updateOrCreate(
            ['from_location' => $from, 'to_location' => $to],
            [
                'per_kg' => (float) $attributes['per_kg'],
                'minimum' => (float) $attributes['minimum'],
                'created_by' => $by->id,
            ],
        );
    }
}
