<?php

namespace App\Services\Recruitment;

use App\Models\ApplicationStageMove;
use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Models\JobOffer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Turning an accepted offer into a person on the payroll.
 *
 * Deliberately the same two records, with the same fields, that the Team
 * screen's "add someone" form creates (App\Livewire\Team\Index::save): an
 * Employee and their first EmploymentContract, together, because an employee
 * with no contract cannot be paid. That screen's logic lives inside a Livewire
 * component this module may not touch, so the shape is mirrored here rather
 * than called — and a test pins the two shapes against each other so they
 * cannot drift silently.
 *
 * What hiring adds over the manual form: `position_id` is set from the
 * vacancy (recruitment always knows the position — it is the thing that was
 * advertised), the free-text `job_title` is filled with the position's title
 * so payroll's snapshotting keeps working exactly as it does today, and the
 * candidate is pointed at the employee they became.
 */
class CandidateHiring
{
    public function hire(JobOffer $offer, User $actor): Employee
    {
        if (! $offer->isAccepted()) {
            throw new RuntimeException('Only an accepted offer hires anybody.');
        }

        $offer->loadMissing(['application.candidate', 'application.vacancy.position.department']);

        $application = $offer->application;
        $candidate = $application->candidate;
        $vacancy = $application->vacancy;
        $position = $vacancy->position;

        if ($candidate->isHired()) {
            throw new RuntimeException($candidate->name().' has already been hired.');
        }

        return DB::transaction(function () use ($offer, $application, $candidate, $vacancy, $position, $actor) {
            $employee = Employee::create([
                'company_id' => $offer->company_id,
                'first_name' => $candidate->first_name,
                'last_name' => $candidate->last_name,
                'email' => $candidate->email,
                'phone' => $candidate->phone,
                // Both the entity link and the free-text snapshot column:
                // payroll and the API still read `job_title`, and 3.9's
                // handoff is explicit that the string stays alongside the id.
                'position_id' => $position->id,
                'job_title' => $position->title,
                'department_id' => $position->department_id,
                'department' => $position->department?->name,
                'hired_on' => $offer->starts_on->toDateString(),
                'status' => 'active',
                // The Team form makes the operator choose; a hire made from an
                // offer letter has not had that conversation yet, so the
                // commonest method is set and the staff page is where it is
                // corrected — an employee with NO method breaks payroll.
                'payment_method' => 'cash',
                'created_by' => $actor->id,
            ]);

            EmploymentContract::create([
                'company_id' => $offer->company_id,
                'employee_id' => $employee->id,
                'type' => 'cdi',
                'job_title' => $position->title,
                'starts_on' => $offer->starts_on->toDateString(),
                'ends_on' => null,
                'base_salary' => (float) $offer->amount,
                'currency' => $offer->currency,
                'status' => 'active',
            ]);

            $candidate->update(['employee_id' => $employee->id]);

            // The one legitimate route into the `hired` stage — the pipeline
            // service refuses it from the board on purpose.
            $application->update(['stage' => 'hired']);

            ApplicationStageMove::create([
                'company_id' => $offer->company_id,
                'job_application_id' => $application->id,
                'from_stage' => 'offer',
                'to_stage' => 'hired',
                'moved_by' => $actor->id,
            ]);

            /*
             * A vacancy that has filled every opening closes itself. Silently
             * staying open would keep collecting applications for a job that
             * no longer exists, which is unfair to the applicants above all.
             */
            if ($vacancy->hiredCount() >= $vacancy->openings && $vacancy->status !== 'closed') {
                $vacancy->update(['status' => 'closed']);
            }

            return $employee;
        });
    }
}
