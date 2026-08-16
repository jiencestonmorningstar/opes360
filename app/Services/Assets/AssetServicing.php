<?php

namespace App\Services\Assets;

use App\Models\AssetMaintenance;
use App\Models\Expense;
use App\Services\Fleet\VehicleUsage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Booking servicing, and marking it done.
 *
 * What this deliberately does not do is record the cost. If a service was
 * paid for there is already an expense for it, and the maintenance record
 * points at that expense. A second amount held here would drift from the
 * first, and the month's spend would depend on which screen you asked.
 */
class AssetServicing
{
    /**
     * Mark a booked visit as carried out.
     *
     * If it repeats, the next one is raised at the same time — from the day
     * the work was actually done, not the day it was due. An oil change six
     * weeks late resets the clock; pretending otherwise books the next one
     * before the engine needs it.
     *
     * A vehicle repeats on distance instead of, or as well as, on months, and
     * the same reasoning applies to the milometer: the next visit counts from
     * the reading the work was actually carried out at. `$odometer` is what
     * the clock said; leave it out and it is read off what the vehicle has
     * been recorded doing.
     */
    public function complete(
        AssetMaintenance $job,
        ?Carbon $on = null,
        ?Expense $expense = null,
        ?int $odometer = null,
    ): AssetMaintenance {
        if ($job->isDone()) {
            throw new RuntimeException(
                "\"{$job->title}\" was already completed on {$job->completed_on->toFormattedDateString()}."
            );
        }

        if ($expense !== null && $expense->company_id !== $job->company_id) {
            throw new RuntimeException('That expense belongs to another company.');
        }

        $on ??= Carbon::today();

        if ($odometer === null && $job->asset !== null && $job->isScheduledByDistance()) {
            $odometer = app(VehicleUsage::class)->odometer($job->asset);
        }

        /*
         * "Every 10 000 km" from a reading nobody knows is not a schedule, it
         * is a guess. Raising the next visit anyway would put a due reading on
         * the record that no one could defend, and it would then sit either
         * permanently overdue or permanently invisible. Better to stop and ask.
         */
        if ($job->interval_km !== null && $odometer === null) {
            throw new RuntimeException(
                "\"{$job->title}\" repeats every {$job->interval_km} km, so the next one has to be ".
                'counted from somewhere. Record the odometer reading this work was done at.'
            );
        }

        return DB::transaction(function () use ($job, $on, $expense, $odometer) {
            $job->forceFill([
                'completed_on' => $on,
                'completed_at_odometer' => $odometer ?? $job->completed_at_odometer,
                'expense_id' => $expense?->id ?? $job->expense_id,
                // Read off the expense rather than typed again, so the two
                // can never disagree about what the work cost.
                'cost' => $expense?->total ?? $job->cost,
                'supplier_id' => $expense?->supplier_id ?? $job->supplier_id,
            ])->save();

            if ($job->interval_months || $job->interval_km) {
                $this->raiseNext($job, $on, $odometer);
            }

            $job->asset?->emitDomainEvent('asset.serviced', [
                'asset_id' => $job->fixed_asset_id,
                'maintenance_id' => $job->id,
                'title' => $job->title,
            ]);

            return $job->refresh();
        });
    }

    protected function raiseNext(AssetMaintenance $job, Carbon $from, ?int $odometer = null): AssetMaintenance
    {
        return AssetMaintenance::create([
            'company_id' => $job->company_id,
            'fixed_asset_id' => $job->fixed_asset_id,
            'kind' => $job->kind,
            'title' => $job->title,
            'notes' => $job->notes,
            // A job that only repeats on distance gets no date at all rather
            // than an invented one — it falls due when the van has driven far
            // enough, and a spare deadline beside it would only be ignored.
            'due_on' => $job->interval_months ? $from->copy()->addMonths($job->interval_months) : null,
            'due_at_odometer' => $job->interval_km ? $odometer + $job->interval_km : null,
            'interval_months' => $job->interval_months,
            'interval_km' => $job->interval_km,
            'supplier_id' => $job->supplier_id,
            'created_by' => $job->created_by,
        ]);
    }
}
