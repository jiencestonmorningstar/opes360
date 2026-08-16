<?php

namespace App\Services\Recruitment;

use App\Models\ApplicationStageMove;
use App\Models\Candidate;
use App\Models\Interview;
use App\Models\InterviewFeedback;
use App\Models\JobApplication;
use App\Models\User;
use App\Models\Vacancy;
use App\Support\UploadGate;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * How an application enters, moves through, and leaves the pipeline.
 *
 * All stage movement happens here, in one transaction with its history row —
 * a `stage` column that can change without a matching ApplicationStageMove
 * would turn the timeline on the application page into a guess.
 */
class RecruitmentPipeline
{
    /** Same private disk as managed documents — no URL, controller-only access. */
    public const CV_DISK = 'documents';

    /** 10 MB. A CV, not a portfolio; this endpoint faces the open internet. */
    public const CV_MAX_BYTES = 10 * 1024 * 1024;

    /**
     * What a CV may be. A subset of DocumentFiler::ALLOWED — same discipline
     * (extension AND sniffed mime must agree), narrowed to the shapes a CV
     * actually takes. No spreadsheets: an .xlsx "CV" on a public endpoint is
     * an attack surface, not a résumé. The list itself lives on UploadGate,
     * the one gate every upload passes; this constant remains the public name
     * the controller's validation rule reads.
     *
     * @var array<string, array<int, string>>
     */
    public const CV_ALLOWED = UploadGate::PURPOSES['cv']['allowed'];

    /**
     * Take an application in — the one write the public page performs.
     *
     * The company comes from the vacancy, which came from the share token,
     * which is the whole tenancy story: nothing here ever reads a company id
     * from input, and every row is stamped with `$vacancy->company_id`
     * explicitly because the visitor has no CurrentCompany to fall back on.
     *
     * @param array{first_name: string, last_name: string, email?: ?string,
     *              phone?: ?string, source?: ?string, cover_note?: ?string} $data
     */
    public function apply(Vacancy $vacancy, array $data, ?UploadedFile $cv = null): JobApplication
    {
        if (! $vacancy->isOpen()) {
            throw new RuntimeException('This vacancy is not accepting applications.');
        }

        $cvRecord = $cv !== null ? $this->storeCv($vacancy, $cv) : [];

        return DB::transaction(function () use ($vacancy, $data, $cvRecord) {
            /*
             * The same person sending their papers twice is a re-send, not two
             * candidates. Matched on email within the company only — matching
             * on name would merge two different Jean Mballas.
             */
            $candidate = null;

            if (! empty($data['email'])) {
                $candidate = Candidate::query()
                    ->withoutGlobalScopes()
                    ->where('company_id', $vacancy->company_id)
                    ->where('email', $data['email'])
                    ->first();
            }

            $candidate ??= Candidate::create([
                'company_id' => $vacancy->company_id,
                'first_name' => trim($data['first_name']),
                'last_name' => trim($data['last_name']),
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'source' => $data['source'] ?? null,
            ]);

            $application = JobApplication::query()
                ->withoutGlobalScopes()
                ->where('vacancy_id', $vacancy->id)
                ->where('candidate_id', $candidate->id)
                ->first();

            if ($application !== null) {
                // A re-application refreshes the papers, never resets the
                // stage — a candidate must not un-reject themselves by
                // submitting the form again.
                $application->update(array_merge([
                    'cover_note' => $data['cover_note'] ?? $application->cover_note,
                ], $cvRecord));

                return $application;
            }

            $application = JobApplication::create(array_merge([
                'company_id' => $vacancy->company_id,
                'vacancy_id' => $vacancy->id,
                'candidate_id' => $candidate->id,
                // Explicit: Model::create() does not read column defaults back.
                'stage' => 'applied',
                'cover_note' => $data['cover_note'] ?? null,
            ], $cvRecord));

            ApplicationStageMove::create([
                'company_id' => $vacancy->company_id,
                'job_application_id' => $application->id,
                'from_stage' => null,
                'to_stage' => 'applied',
                'moved_by' => null, // the applicant themselves — not a user
            ]);

            return $application;
        });
    }

    /**
     * Move an application forward (or back) through the pipeline.
     *
     * `hired` is deliberately refused here: hiring is not a drag on a board,
     * it is accepting an approved offer, and it happens only through
     * JobOffers::accept() so an Employee can never appear without one.
     */
    public function moveStage(JobApplication $application, string $to, User $actor, ?string $reason = null): JobApplication
    {
        if (! array_key_exists($to, JobApplication::STAGES)) {
            throw new RuntimeException("There is no [{$to}] stage.");
        }

        if ($to === 'hired') {
            throw new RuntimeException('Hiring happens by accepting an approved offer, not by moving the card.');
        }

        if ($application->isHired()) {
            throw new RuntimeException('This person has been hired. Their application no longer moves.');
        }

        if ($to === 'rejected') {
            return $this->reject($application, $actor, $reason);
        }

        return $this->record($application, $to, $actor, $reason);
    }

    /** Rejection, from any stage, with the reason kept on both the card and the move. */
    public function reject(JobApplication $application, User $actor, ?string $reason = null): JobApplication
    {
        if ($application->isHired()) {
            throw new RuntimeException('This person has been hired. Their application no longer moves.');
        }

        return DB::transaction(function () use ($application, $actor, $reason) {
            $moved = $this->record($application, 'rejected', $actor, $reason);
            $moved->update(['rejection_reason' => $reason]);

            return $moved->fresh();
        });
    }

    /**
     * Schedule an interview and choose its panel in one act.
     *
     * One blank scorecard per interviewer is created immediately — the panel
     * and the scorecards are the same rows, so "who was asked to interview"
     * can never disagree with "who was asked to score".
     *
     * @param  array<int, int>  $interviewerIds  users.id values
     */
    public function scheduleInterview(
        JobApplication $application,
        string $scheduledAt,
        array $interviewerIds,
        User $actor,
        ?string $location = null,
        ?string $notes = null,
    ): Interview {
        if (! $application->isActive()) {
            throw new RuntimeException('Only an application still in the pipeline can be interviewed.');
        }

        if ($interviewerIds === []) {
            throw new RuntimeException('An interview needs at least one interviewer.');
        }

        return DB::transaction(function () use ($application, $scheduledAt, $interviewerIds, $actor, $location, $notes) {
            $interview = Interview::create([
                'company_id' => $application->company_id,
                'job_application_id' => $application->id,
                'scheduled_at' => $scheduledAt,
                'location' => $location,
                'notes' => $notes,
                'status' => 'scheduled',
                'created_by' => $actor->id,
            ]);

            foreach (array_unique($interviewerIds) as $userId) {
                InterviewFeedback::create([
                    'company_id' => $application->company_id,
                    'interview_id' => $interview->id,
                    'interviewer_id' => $userId,
                ]);
            }

            // Scheduling the first interview IS reaching the interview stage;
            // asking someone to also drag the card is asking for the two to
            // disagree.
            if (in_array($application->stage, ['applied', 'screening'], true)) {
                $this->record($application, 'interview', $actor, null);
            }

            return $interview;
        });
    }

    /** One interviewer filling in their own card. */
    public function recordFeedback(Interview $interview, User $interviewer, ?int $rating, ?string $notes): InterviewFeedback
    {
        $card = $interview->feedback()->where('interviewer_id', $interviewer->id)->first();

        if ($card === null) {
            throw new RuntimeException('Only somebody on the panel can score this interview.');
        }

        if ($rating !== null && ($rating < 1 || $rating > 5)) {
            throw new RuntimeException('A rating is between 1 and 5.');
        }

        $card->update([
            'rating' => $rating,
            'notes' => $notes,
            'submitted_at' => now(),
        ]);

        return $card->fresh();
    }

    /**
     * Erase a rejected candidate for real — the GDPR-shaped exit.
     *
     * Refused while any application of theirs is still live, and refused
     * outright for somebody who was hired: an employee's recruitment trail is
     * part of an employment record with its own retention rules. What is
     * removed: the CV files, every application with its history and
     * interviews (by cascade), and the candidate row itself. Force deletes,
     * because a soft-deleted CV is not an erasure.
     */
    public function purge(Candidate $candidate, User $actor): void
    {
        if ($candidate->isHired()) {
            throw new RuntimeException(
                $candidate->name().' was hired; their record is part of an employment file, not a rejection to erase.'
            );
        }

        $applications = $candidate->applications()->withTrashed()->get();

        if ($applications->contains(fn (JobApplication $a) => $a->isActive())) {
            throw new RuntimeException('Reject or conclude their open applications before purging.');
        }

        DB::transaction(function () use ($candidate, $applications) {
            foreach ($applications as $application) {
                if ($application->cv_path !== null) {
                    Storage::disk($application->cv_disk ?? self::CV_DISK)->delete($application->cv_path);
                }

                // Stage moves, interviews and feedback go with it by FK cascade.
                $application->forceDelete();
            }

            $candidate->forceDelete();
        });
    }

    /**
     * Store the CV on the private documents disk.
     *
     * The discipline itself — extension against sniffed mime (never the
     * client's claim), the size cap, and the ClamAV pass when a daemon is
     * configured — lives in UploadGate under the narrower `cv` purpose. A
     * refusal surfaces as the gate's polite message, which deliberately
     * never tells a public visitor what stands behind the check.
     *
     * @return array{cv_disk: string, cv_path: string, cv_name: string, cv_mime: ?string, cv_size: int|false}
     */
    protected function storeCv(Vacancy $vacancy, UploadedFile $cv): array
    {
        app(UploadGate::class)->accept($cv, 'cv');

        $path = $cv->store('c/'.$vacancy->company_id.'/recruitment', self::CV_DISK);

        if ($path === false) {
            throw new RuntimeException('The file could not be stored.');
        }

        return [
            'cv_disk' => self::CV_DISK,
            'cv_path' => $path,
            'cv_name' => (string) $cv->getClientOriginalName(),
            'cv_mime' => $cv->getMimeType(),
            'cv_size' => $cv->getSize(),
        ];
    }

    /** The stage change and its history row, atomically. */
    protected function record(JobApplication $application, string $to, ?User $actor, ?string $reason): JobApplication
    {
        return DB::transaction(function () use ($application, $to, $actor, $reason) {
            $from = $application->stage;

            $application->update(['stage' => $to]);

            ApplicationStageMove::create([
                'company_id' => $application->company_id,
                'job_application_id' => $application->id,
                'from_stage' => $from,
                'to_stage' => $to,
                'reason' => $reason,
                'moved_by' => $actor?->id,
            ]);

            return $application->fresh();
        });
    }
}
