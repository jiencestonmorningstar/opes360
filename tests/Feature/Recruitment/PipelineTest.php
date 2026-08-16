<?php

namespace Tests\Feature\Recruitment;

use App\Models\ApplicationStageMove;
use App\Models\Candidate;
use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Models\InterviewFeedback;
use App\Models\JobApplication;
use App\Models\Role;
use App\Models\User;
use RuntimeException;

/**
 * The pipeline itself: applied → screening → interview → offer → hired, the
 * history that trails it, and the one rule that matters most — an Employee
 * only ever appears through an accepted, approved offer.
 */
class PipelineTest extends RecruitmentTestCase
{
    public function test_the_full_pipeline_produces_a_real_employee_linked_to_the_position(): void
    {
        $application = $this->application();

        $this->pipeline()->moveStage($application, 'screening', $this->owner);
        $interview = $this->pipeline()->scheduleInterview(
            $application->fresh(), now()->addDays(3)->toDateTimeString(), [$this->owner->id], $this->owner,
        );
        $this->pipeline()->recordFeedback($interview, $this->owner, 4, 'Knows the routes well.');

        $offer = $this->approvedOffer($application->fresh());

        $this->offers()->accept($offer, $this->owner);

        $employee = Employee::query()->where('first_name', 'Jean')->where('last_name', 'Mballa')->first();

        $this->assertNotNull($employee, 'Accepting the offer should have created an employee.');
        $this->assertSame($this->position->id, $employee->position_id);
        $this->assertSame($this->position->title, $employee->job_title);
        $this->assertSame('active', $employee->status);

        // Hired through the same shape the Team screen creates: a person AND
        // their first contract, because an employee without one cannot be paid.
        $contract = EmploymentContract::query()->where('employee_id', $employee->id)->first();
        $this->assertNotNull($contract, 'A hire without a contract cannot be paid.');
        $this->assertSame(250_000.0, (float) $contract->base_salary);
        $this->assertSame('active', $contract->status);

        // The candidate points at who they became; the application says hired.
        $this->assertSame($employee->id, $application->fresh()->candidate->employee_id);
        $this->assertSame('hired', $application->fresh()->stage);
    }

    public function test_every_move_is_kept_with_who_made_it(): void
    {
        $application = $this->application();

        $this->pipeline()->moveStage($application, 'screening', $this->owner);

        $moves = ApplicationStageMove::query()
            ->where('job_application_id', $application->id)
            ->orderBy('created_at')
            ->get();

        $this->assertSame(['applied', 'screening'], $moves->pluck('to_stage')->all());
        $this->assertNull($moves->first()->moved_by, 'The original application is the applicant\'s own act.');
        $this->assertSame($this->owner->id, $moves->last()->moved_by);
    }

    public function test_rejection_carries_a_reason_from_any_stage(): void
    {
        $application = $this->application();

        $this->pipeline()->reject($application, $this->owner, 'No driving licence.');

        $fresh = $application->fresh();
        $this->assertSame('rejected', $fresh->stage);
        $this->assertSame('No driving licence.', $fresh->rejection_reason);
        $this->assertSame(
            'No driving licence.',
            $fresh->stageMoves()->where('to_stage', 'rejected')->value('reason'),
        );
    }

    public function test_the_board_cannot_hire_anybody(): void
    {
        $application = $this->application();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('accepting an approved offer');

        $this->pipeline()->moveStage($application, 'hired', $this->owner);
    }

    public function test_a_hired_application_no_longer_moves(): void
    {
        $application = $this->application();
        $this->offers()->accept($this->approvedOffer($application), $this->owner);

        $this->expectException(RuntimeException::class);

        $this->pipeline()->moveStage($application->fresh(), 'screening', $this->owner);
    }

    public function test_scheduling_an_interview_creates_a_blank_scorecard_per_interviewer(): void
    {
        $second = $this->memberUser();
        $application = $this->application();

        $interview = $this->pipeline()->scheduleInterview(
            $application, now()->addDay()->toDateTimeString(), [$this->owner->id, $second->id], $this->owner,
        );

        $this->assertSame(2, $interview->feedback()->count());
        $this->assertSame(0, $interview->feedback()->whereNotNull('submitted_at')->count());
        $this->assertSame('interview', $application->fresh()->stage);
    }

    public function test_somebody_off_the_panel_cannot_score_the_interview(): void
    {
        $application = $this->application();
        $interview = $this->pipeline()->scheduleInterview(
            $application, now()->addDay()->toDateTimeString(), [$this->owner->id], $this->owner,
        );

        $outsider = $this->memberUser();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('on the panel');

        $this->pipeline()->recordFeedback($interview, $outsider, 5, 'Great!');
    }

    public function test_a_blank_scorecard_does_not_drag_the_average_down(): void
    {
        $second = $this->memberUser();
        $application = $this->application();
        $interview = $this->pipeline()->scheduleInterview(
            $application, now()->addDay()->toDateTimeString(), [$this->owner->id, $second->id], $this->owner,
        );

        $this->pipeline()->recordFeedback($interview, $this->owner, 4, null);

        $this->assertSame(4.0, $interview->fresh()->averageRating());
    }

    // ── GDPR-shaped exit ────────────────────────────────────────────────

    public function test_a_rejected_candidate_can_be_purged_completely(): void
    {
        $application = $this->application();
        $this->pipeline()->scheduleInterview(
            $application, now()->addDay()->toDateTimeString(), [$this->owner->id], $this->owner,
        );
        $this->pipeline()->reject($application->fresh(), $this->owner, 'Not a fit.');

        $candidate = $application->candidate;

        $this->pipeline()->purge($candidate, $this->owner);

        $this->assertDatabaseMissing('candidates', ['id' => $candidate->id]);
        $this->assertDatabaseMissing('job_applications', ['id' => $application->id]);
        $this->assertSame(0, ApplicationStageMove::query()->where('job_application_id', $application->id)->count());
        $this->assertSame(0, InterviewFeedback::query()->count());
    }

    public function test_a_live_application_blocks_the_purge(): void
    {
        $application = $this->application();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('open applications');

        $this->pipeline()->purge($application->candidate, $this->owner);
    }

    public function test_a_hired_candidate_cannot_be_purged(): void
    {
        $application = $this->application();
        $this->offers()->accept($this->approvedOffer($application), $this->owner);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('employment file');

        $this->pipeline()->purge($application->candidate->fresh(), $this->owner);
    }

    public function test_applying_twice_with_the_same_email_is_one_candidate_one_application(): void
    {
        $vacancy = $this->vacancy();

        $this->application($vacancy);
        $this->application($vacancy, ['cover_note' => 'Second try, same person.']);

        $this->assertSame(1, Candidate::query()->count());
        $this->assertSame(1, JobApplication::query()->count());
        $this->assertSame('Second try, same person.', JobApplication::query()->first()->cover_note);
    }

    protected function memberUser(): User
    {
        $user = User::factory()->create();

        $this->joinCompany($this->company, $user, Role::MANAGER);
        $user->forceFill(['current_company_id' => $this->company->id])->save();

        return $user;
    }
}
