<?php

namespace App\Services\Recruitment;

use App\Models\BusinessDocument;
use App\Models\JobApplication;
use App\Models\JobOffer;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowInstance;
use App\Services\DocumentComposer;
use App\Services\Workflow\WorkflowEngine;
use App\Support\DocumentKinds;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Making an offer, getting it signed off, and hearing the answer.
 *
 * There is no `approve()` here, for the same reason ContractLifecycle has
 * none: the offer is handed to the shared WorkflowEngine and its answer is
 * read back through Approvable. The letter is a BusinessDocument from the
 * existing `offer_letter` template — recruitment writes no letters of its own.
 */
class JobOffers
{
    public function __construct(
        protected WorkflowEngine $engine,
        protected DocumentComposer $composer,
    ) {}

    /**
     * Draft an offer and its letter together.
     *
     * The letter is generated now, while the offer is still a draft, so the
     * approver reads the actual words the candidate would receive — approving
     * an amount and then generating a letter afterwards would mean nobody
     * with authority ever saw the document itself.
     */
    public function make(JobApplication $application, float $amount, string $startsOn, User $actor): JobOffer
    {
        if (! $application->isActive()) {
            throw new RuntimeException('Only an application still in the pipeline can receive an offer.');
        }

        if ($amount <= 0) {
            throw new RuntimeException('An offer needs an amount.');
        }

        if ($application->currentOffer() !== null) {
            throw new RuntimeException('There is already an offer on the table. Withdraw it before making another.');
        }

        $application->loadMissing(['candidate', 'vacancy.position', 'company']);

        $company = $application->company;
        $currency = $company->currency ?: 'XAF';

        return DB::transaction(function () use ($application, $amount, $startsOn, $actor, $company, $currency) {
            $fields = [
                'candidate_name' => $application->candidate->name(),
                'position' => $application->vacancy->title(),
                'salary' => $this->presentSalary($amount, $currency),
                'start_date' => $startsOn,
            ];

            $letter = BusinessDocument::create([
                'company_id' => $application->company_id,
                'template' => 'offer_letter',
                'title' => 'Offer — '.$application->candidate->name(),
                'recipient' => $application->candidate->name(),
                'kind' => DocumentKinds::exists('letter') ? 'letter' : 'document',
                // An offer names somebody's salary; not for the whole office.
                'security' => 'confidential',
                'fields' => $fields,
                'body' => $this->composer->merge('offer_letter', $fields, $company),
                'status' => 'draft',
                'owner_id' => $actor->id,
                'created_by' => $actor->id,
            ]);

            $offer = JobOffer::create([
                'company_id' => $application->company_id,
                'job_application_id' => $application->id,
                'amount' => $amount,
                'currency' => $currency,
                'starts_on' => $startsOn,
                'status' => 'draft',
                'business_document_id' => $letter->id,
                'created_by' => $actor->id,
            ]);

            // Reaching the offer stage is a consequence of the offer existing,
            // not a separate drag on the board.
            if ($application->isActive() && $application->stage !== 'offer') {
                app(RecruitmentPipeline::class)->moveStage($application, 'offer', $actor);
            }

            return $offer;
        });
    }

    /** Hand it to the shared engine. The outcome comes back through Approvable. */
    public function submit(JobOffer $offer, User $submitter, ?Workflow $workflow = null): WorkflowInstance
    {
        if ($offer->status !== 'draft') {
            throw new RuntimeException('This offer is already '.$offer->statusLabel().'.');
        }

        $workflow ??= Workflow::defaultFor(JobOffer::class);

        if ($workflow === null) {
            throw new RuntimeException(
                'No approval workflow is set up for job offers. Define one before submitting.'
            );
        }

        $offer->update(['status' => 'pending']);

        return $this->engine->start($offer, $workflow, $submitter);
    }

    /**
     * The candidate said yes — which is the moment somebody joins the payroll.
     *
     * Gated on the ENGINE's verdict, not on the mirrored status column: a row
     * whose status somebody set to "approved" by hand must still be refused if
     * no workflow instance actually finished approved. Hiring itself is
     * CandidateHiring's job, so the Employee comes into being through the same
     * shape of record the Team screen creates.
     */
    public function accept(JobOffer $offer, User $actor): JobOffer
    {
        if ($offer->isAccepted()) {
            return $offer;
        }

        if (in_array($offer->status, ['declined', 'withdrawn'], true)) {
            throw new RuntimeException('This offer is '.$offer->statusLabel().' and cannot be accepted.');
        }

        if (! $offer->isApproved()) {
            throw new RuntimeException(
                'This offer has not been approved yet. It cannot be accepted before the approval finishes.'
            );
        }

        return DB::transaction(function () use ($offer, $actor) {
            $offer->update(['status' => 'accepted', 'accepted_at' => now()]);

            app(CandidateHiring::class)->hire($offer, $actor);

            return $offer->fresh();
        });
    }

    public function decline(JobOffer $offer, User $actor, ?string $reason = null): JobOffer
    {
        if ($offer->isAccepted()) {
            throw new RuntimeException('This offer was accepted; it can no longer be declined.');
        }

        $offer->update([
            'status' => 'declined',
            'declined_at' => now(),
            'decline_reason' => $reason,
        ]);

        return $offer->fresh();
    }

    public function withdraw(JobOffer $offer, User $actor): JobOffer
    {
        if ($offer->isAccepted()) {
            throw new RuntimeException('This offer was accepted; withdraw the employment, not the offer.');
        }

        $offer->update(['status' => 'withdrawn']);

        return $offer->fresh();
    }

    /**
     * "450,000 XAF per month" — words for a letter, not a database dump.
     * Trailing .00 is dropped; 350000.50 keeps its centimes.
     */
    protected function presentSalary(float $amount, string $currency): string
    {
        $formatted = number_format($amount, 2);

        if (str_ends_with($formatted, '.00')) {
            $formatted = substr($formatted, 0, -3);
        }

        return $formatted.' '.$currency.' per month';
    }
}
