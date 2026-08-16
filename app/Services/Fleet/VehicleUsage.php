<?php

namespace App\Services\Fleet;

use App\Models\AssetMaintenance;
use App\Models\Expense;
use App\Models\FixedAsset;
use App\Models\FuelLog;
use App\Models\VehicleTrip;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * How far a vehicle has gone, and what it drank getting there.
 *
 * The one thing an asset register has no concept of is use. A generator is
 * worth what it is worth whether it ran or not; a van is serviced, fuelled and
 * written off against how far it has been driven. That is what this adds, and
 * it is the only thing here that assets did not already do.
 */
class VehicleUsage
{
    /**
     * The current reading, worked out rather than stored.
     *
     * Three things move a milometer forward in this system — a trip, a fill at
     * the pump, and a service booked in at a reading — and the highest of them
     * is where the van is. A column holding the answer would go stale the first
     * time anyone corrected a trip, and then the servicing schedule would be
     * counting from a number nobody could reproduce.
     *
     * Null means nothing has been recorded, which is not the same as zero. A
     * van reading zero is brand new; a van reading nothing is unmeasured, and
     * the difference decides whether servicing can be scheduled at all.
     */
    public function odometer(FixedAsset $asset): ?int
    {
        $readings = [
            VehicleTrip::where('fixed_asset_id', $asset->id)->max('end_odometer'),
            FuelLog::where('fixed_asset_id', $asset->id)->max('odometer'),
            AssetMaintenance::where('fixed_asset_id', $asset->id)->max('completed_at_odometer'),
        ];

        $readings = array_filter($readings, fn ($r) => $r !== null);

        return $readings === [] ? null : (int) max($readings);
    }

    /**
     * @param  array{driver_id?: ?int, trip_date: string|Carbon, start_odometer: int|string, end_odometer: int|string, purpose?: ?string, notes?: ?string}  $data
     */
    public function logTrip(FixedAsset $asset, array $data, ?int $userId = null): VehicleTrip
    {
        $start = (int) $data['start_odometer'];
        $end = (int) $data['end_odometer'];

        // A milometer does not run backwards. Accepting this would put a
        // negative distance into every average the fleet ever reports, and
        // nobody would find it again.
        if ($end < $start) {
            throw new RuntimeException(
                "That trip runs backwards: it ends at {$end} km having started at {$start} km. ".
                'Check which reading is which.'
            );
        }

        return VehicleTrip::create([
            'company_id' => $asset->company_id,
            'fixed_asset_id' => $asset->id,
            'driver_id' => $data['driver_id'] ?? null,
            'trip_date' => $data['trip_date'],
            'start_odometer' => $start,
            'end_odometer' => $end,
            'purpose' => $data['purpose'] ?? null,
            'notes' => $data['notes'] ?? null,
            'created_by' => $userId,
        ]);
    }

    /**
     * @param  array{driver_id?: ?int, filled_on: string|Carbon, litres: float|string, odometer?: int|string|null, is_full_tank?: bool, supplier_id?: ?string}  $data
     */
    public function logFuel(
        FixedAsset $asset,
        array $data,
        ?Expense $expense = null,
        ?int $userId = null,
    ): FuelLog {
        if ($expense !== null && $expense->company_id !== $asset->company_id) {
            throw new RuntimeException('That expense belongs to another company.');
        }

        if ((float) $data['litres'] <= 0) {
            throw new RuntimeException('A fill of no fuel is not a fill. Enter the litres that went in.');
        }

        return FuelLog::create([
            'company_id' => $asset->company_id,
            'fixed_asset_id' => $asset->id,
            'driver_id' => $data['driver_id'] ?? null,
            'filled_on' => $data['filled_on'],
            'litres' => $data['litres'],
            'odometer' => ($data['odometer'] ?? null) === null || $data['odometer'] === ''
                ? null
                : (int) $data['odometer'],
            // Model::create() does not backfill the column default, so the
            // honest default is stated here rather than left to the schema.
            'is_full_tank' => $data['is_full_tank'] ?? true,
            'expense_id' => $expense?->id,
            'supplier_id' => $data['supplier_id'] ?? $expense?->supplier_id,
            'created_by' => $userId,
        ]);
    }

    /**
     * Litres per 100 km, measured tank to tank.
     *
     * The first fill's litres are left out on purpose: that fuel went into the
     * tank before the first reading was taken, so it did not pay for any of the
     * distance being measured. Counting it flatters the figure by about one
     * tankful, which on a light van is most of the answer.
     *
     * Only fills with a reading count. A fill nobody wrote the clock down for
     * is still a real expense; it just cannot tell you anything about economy.
     */
    public function consumptionPer100Km(FixedAsset $asset): ?float
    {
        $fills = FuelLog::where('fixed_asset_id', $asset->id)
            ->whereNotNull('odometer')
            ->orderBy('odometer')
            ->get(['litres', 'odometer']);

        if ($fills->count() < 2) {
            return null;
        }

        $distance = (int) $fills->last()->odometer - (int) $fills->first()->odometer;

        if ($distance <= 0) {
            return null;
        }

        $litres = (float) $fills->skip(1)->sum(fn ($fill) => (float) $fill->litres);

        return $litres <= 0 ? null : round($litres / $distance * 100, 2);
    }

    /**
     * How far it went between two dates.
     *
     * Bounded on start-of-day and end-of-day because the date cast stores
     * midnight: a range taken on the raw dates ends at 00:00 on the closing
     * day and silently loses everything driven on it.
     */
    public function distanceTravelled(FixedAsset $asset, Carbon $from, Carbon $to): int
    {
        return (int) VehicleTrip::where('fixed_asset_id', $asset->id)
            ->betweenDates($from, $to)
            ->get(['start_odometer', 'end_odometer'])
            ->sum(fn (VehicleTrip $trip) => $trip->distance());
    }
}
