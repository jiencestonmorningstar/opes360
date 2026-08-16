<?php

namespace App\Services\Fleet;

use App\Models\AssetMaintenance;
use App\Models\FixedAsset;
use App\Models\VehicleDetail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The two things about a fleet that go wrong silently.
 *
 * Insurance lapses on a date nobody is watching, and a service falls due on a
 * reading nobody is adding up. Neither announces itself, and both are found out
 * at the roadside. Putting them in front of somebody is the whole feature.
 */
class FleetAlerts
{
    public function __construct(protected VehicleUsage $usage) {}

    /**
     * Papers running out, soonest first — including the ones already lapsed.
     *
     * Expired papers are in the list rather than filtered out of it. A van on
     * the road today with cover that ran out last week is the urgent case, and
     * a screen that only showed what was *about* to expire would hide it the
     * moment it became a real problem.
     *
     * @return Collection<int, array{asset: FixedAsset, vehicle: VehicleDetail, kind: string, expires_on: Carbon, days: int, lapsed: bool}>
     */
    public function expiringPapers(int $withinDays = 30): Collection
    {
        // Bounded on end-of-day: the dates are cast, so they come back as
        // midnight and a paper expiring on the closing day of the window would
        // otherwise fall outside it by a matter of hours.
        $horizon = Carbon::today()->addDays($withinDays)->endOfDay();

        return VehicleDetail::query()
            ->with('asset')
            ->where(function ($query) use ($horizon) {
                foreach (array_keys(VehicleDetail::PAPERS) as $column) {
                    $query->orWhere($column, '<=', $horizon);
                }
            })
            ->get()
            ->flatMap(function (VehicleDetail $vehicle) use ($withinDays) {
                return collect($vehicle->papers())
                    ->filter(fn (array $paper) => $paper['days'] <= $withinDays)
                    ->map(fn (array $paper) => $paper + [
                        'asset' => $vehicle->asset,
                        'vehicle' => $vehicle,
                    ]);
            })
            ->sortBy('days')
            ->values();
    }

    /**
     * Servicing that is due, whichever clock it is due on.
     *
     * One list. A van overdue because the calendar came round and a van overdue
     * because it has driven far enough are the same problem to whoever has to
     * book the garage, and splitting them across two screens would guarantee
     * one of the two went unread.
     *
     * @return Collection<int, AssetMaintenance>
     */
    public function servicingDue(): Collection
    {
        $jobs = AssetMaintenance::query()
            ->outstanding()
            ->with('asset')
            ->get();

        // Worked out once per vehicle rather than once per job: a fleet with a
        // dozen jobs against one van would otherwise scan the trips twelve times.
        $odometers = [];

        return $jobs
            ->filter(function (AssetMaintenance $job) use (&$odometers) {
                if ($job->isOverdue()) {
                    return true;
                }

                if ($job->due_at_odometer === null || $job->asset === null) {
                    return false;
                }

                $odometers[$job->fixed_asset_id] ??= $this->usage->odometer($job->asset);

                return $job->isDueAtDistance($odometers[$job->fixed_asset_id]);
            })
            ->values();
    }
}
