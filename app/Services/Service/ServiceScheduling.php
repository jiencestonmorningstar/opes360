<?php

namespace App\Services\Service;

use App\Models\ProjectTimeEntry;
use App\Models\ServiceJob;
use App\Models\ServiceJobPart;
use App\Models\ServiceTicket;
use App\Models\User;
use App\Services\Assets\AssetServicing;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Booking visits, and recording what happened on them.
 *
 * Three deliberate absences here, each of which would have been a second copy
 * of something the product already has:
 *
 *  - hours go on `project_time_entries`, the one timesheet;
 *  - servicing an owned machine is completed on `AssetMaintenance` through
 *    `AssetServicing`, not re-recorded as a service history of its own;
 *  - parts are pointed at the catalogue and priced on the line, and no stock
 *    movement is written from here — the stock ledger belongs to Inventory.
 */
class ServiceScheduling
{
    public function __construct(
        protected TicketNumbers $numbers,
        protected AssetServicing $servicing,
    ) {}

    public function schedule(?ServiceTicket $ticket, array $attributes, User $by): ServiceJob
    {
        return DB::transaction(function () use ($ticket, $attributes, $by) {
            $job = ServiceJob::create(array_merge(
                Arr::only($attributes, [
                    'technician_id', 'scheduled_for', 'estimated_minutes',
                    'fixed_asset_id', 'asset_maintenance_id', 'on_site_notes',
                ]),
                [
                    'ticket_id' => $ticket?->id,
                    'reference' => $attributes['reference'] ?? $this->numbers->nextJob(),
                    'status' => 'scheduled',
                    // Inherited from the ticket where the caller says nothing:
                    // a visit against equipment the business owns is its own
                    // cost, and a visit for a customer is chargeable.
                    'is_billable' => $attributes['is_billable'] ?? true,
                    // A job against equipment defaults to the ticket's machine
                    // rather than needing to be told twice.
                    'fixed_asset_id' => $attributes['fixed_asset_id'] ?? $ticket?->fixed_asset_id,
                    'created_by' => $by->id,
                ],
            ));

            $job->emitDomainEvent('service.job.scheduled', [
                'job_id' => $job->id,
                'ticket_id' => $ticket?->id,
                'technician_id' => $job->technician_id,
                'scheduled_for' => $job->scheduled_for?->toIso8601String(),
            ]);

            return $job->refresh();
        });
    }

    public function start(ServiceJob $job, ?Carbon $at = null): ServiceJob
    {
        if ($job->isComplete()) {
            throw new RuntimeException("{$job->reference} has already been completed.");
        }

        $job->forceFill(['status' => 'in_progress', 'started_at' => $at ?? now()])->save();

        return $job->refresh();
    }

    /**
     * The visit is done.
     *
     * Where the visit *was* the servicing the asset register was expecting,
     * that record is completed through `AssetServicing` — which is also what
     * raises the next one, on its own interval rules. Setting `completed_on`
     * here instead would give the business a serviced machine with no next
     * service booked, and nobody would notice until it broke.
     */
    public function complete(ServiceJob $job, User $by, ?string $notes = null, ?Carbon $at = null): ServiceJob
    {
        if ($job->isComplete()) {
            throw new RuntimeException(
                "{$job->reference} was already completed on {$job->completed_at->toDayDateTimeString()}."
            );
        }

        $at ??= now();

        return DB::transaction(function () use ($job, $by, $notes, $at) {
            $job->forceFill([
                'status' => 'completed',
                'completed_at' => $at,
                'started_at' => $job->started_at ?? $at,
                'on_site_notes' => $notes ?? $job->on_site_notes,
            ])->save();

            if ($job->maintenance !== null && ! $job->maintenance->isDone()) {
                $this->servicing->complete($job->maintenance, $at->copy()->startOfDay());
            }

            $job->emitDomainEvent('service.job.completed', [
                'job_id' => $job->id,
                'ticket_id' => $job->ticket_id,
                'completed_by' => $by->id,
                'hours' => $job->hoursLogged(),
            ]);

            return $job->refresh();
        });
    }

    public function cancel(ServiceJob $job, ?string $reason = null): ServiceJob
    {
        if ($job->isComplete()) {
            throw new RuntimeException("{$job->reference} has been done and cannot be cancelled.");
        }

        $job->forceFill([
            'status' => 'cancelled',
            'on_site_notes' => $reason ?? $job->on_site_notes,
        ])->save();

        return $job->refresh();
    }

    /**
     * A technician's hours, on the timesheet that already exists.
     *
     * Note what is copied onto the row: billability and rate, exactly as a
     * project entry does, because a rate that changes in March must not
     * restate what January's visit cost. And the project link comes off the
     * ticket, so a service contract run as a project shows these hours in its
     * cost-to-date without anybody logging them twice.
     */
    public function logTime(ServiceJob $job, User $technician, float $hours, array $attributes = []): ProjectTimeEntry
    {
        if ($hours <= 0) {
            throw new RuntimeException('Time logged has to be more than nothing.');
        }

        return ProjectTimeEntry::create(array_merge(
            Arr::only($attributes, ['notes', 'task_id']),
            [
                'company_id' => $job->company_id,
                'service_job_id' => $job->id,
                'project_id' => $attributes['project_id'] ?? $job->ticket?->project_id,
                'user_id' => $technician->id,
                'worked_on' => $attributes['worked_on'] ?? ($job->completed_at ?? $job->scheduled_for ?? now())->toDateString(),
                'hours' => $hours,
                'is_billable' => $attributes['is_billable'] ?? $job->is_billable,
                'hourly_rate' => $attributes['hourly_rate'] ?? null,
            ],
        ));
    }

    public function addPart(ServiceJob $job, array $attributes): ServiceJobPart
    {
        if (($attributes['item_id'] ?? null) === null && ($attributes['description'] ?? null) === null) {
            throw new RuntimeException('A part needs either a catalogue item or a description.');
        }

        return ServiceJobPart::create(array_merge(
            Arr::only($attributes, ['item_id', 'description', 'unit']),
            [
                'company_id' => $job->company_id,
                'service_job_id' => $job->id,
                'quantity' => $attributes['quantity'] ?? 1,
                'unit_price' => $attributes['unit_price'] ?? 0,
                'is_billable' => $attributes['is_billable'] ?? true,
            ],
        ));
    }
}
