<?php

namespace App\Services\Compliance;

use App\Models\BusinessDocument;
use App\Models\ComplianceFiling;
use App\Models\ComplianceObligation;
use App\Models\User;
use App\Models\Workflow;
use App\Services\Documents\DocumentLinker;
use App\Services\Workflow\WorkflowEngine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Meeting a statutory obligation, and raising the next one.
 *
 * Deliberately thin in two places. Evidence is not stored here — it is a
 * BusinessDocument, linked through the documents module's own linker, because
 * a compliance module with its own file store is a second document system
 * that will immediately disagree with the first about retention and legal
 * hold. Sign-off is not decided here either — it goes through the one
 * workflow engine, and this service only reads the verdict back.
 */
class ComplianceRegister
{
    public function __construct(
        protected DocumentLinker $linker,
        protected WorkflowEngine $workflows,
    ) {}

    /**
     * Open the occurrence that is currently due.
     *
     * Returns the one already open rather than creating a second. Two people
     * starting the same return would otherwise produce two declarations
     * against one period, and the register would then hold two different
     * answers to whether that period was filed.
     */
    public function raise(ComplianceObligation $obligation, ?Carbon $dueOn = null): ComplianceFiling
    {
        $existing = $obligation->openFiling();

        if ($existing !== null) {
            return $existing;
        }

        $dueOn ??= $obligation->next_due_on ?? Carbon::today();

        return ComplianceFiling::create([
            'company_id' => $obligation->company_id,
            'compliance_obligation_id' => $obligation->id,
            'due_on' => $dueOn->copy()->startOfDay(),
            // Model::create() does not backfill a column default, so the
            // states this row can be in are set here rather than assumed.
            'status' => 'draft',
            'created_by' => auth()->id(),
        ]);
    }

    /**
     * Hand the filing on: either to an approver, or straight to done.
     *
     * @param  array<string, mixed>  $details
     */
    public function submit(ComplianceFiling $filing, User $actor, array $details = []): ComplianceFiling
    {
        if (! $filing->isOpen()) {
            throw new RuntimeException('This filing has already been dealt with.');
        }

        $this->fill($filing, $details);

        if (! $filing->obligation->requires_approval) {
            return $this->complete($filing, $details['completed_on'] ?? null, $details);
        }

        $workflow = Workflow::defaultFor(ComplianceFiling::class);

        /*
         * No path defined is a refusal, not a wave-through. The opposite
         * choice would mean the obligations somebody cared enough about to
         * require sign-off on were exactly the ones that skipped it, and
         * nothing anywhere would say so.
         */
        if ($workflow === null) {
            throw new RuntimeException(
                'This obligation requires sign-off, but no sign-off workflow is defined for compliance filings.'
            );
        }

        return DB::transaction(function () use ($filing, $workflow, $actor) {
            $filing->forceFill(['status' => 'submitted'])->save();

            $this->workflows->start($filing, $workflow, $actor);

            return $filing->refresh();
        });
    }

    /**
     * Mark the occurrence met, and move the obligation on.
     *
     * The next deadline comes from `schedule_basis`, and that is the one
     * decision in this module worth challenging. Servicing a van counts from
     * when the work was done; a statutory return does not, because the
     * authority sets the calendar and being late does not buy more time. See
     * the migration for both cases spelled out.
     *
     * @param  array<string, mixed>  $details
     */
    public function complete(ComplianceFiling $filing, ?Carbon $on = null, array $details = []): ComplianceFiling
    {
        if ($filing->isDone()) {
            throw new RuntimeException(sprintf(
                'This filing was already completed on %s.',
                $filing->completed_on?->toFormattedDateString() ?? 'an earlier date',
            ));
        }

        $on = $on ? $on->copy()->startOfDay() : Carbon::today();

        return DB::transaction(function () use ($filing, $on, $details) {
            $this->fill($filing, $details);

            $filing->forceFill([
                'status' => 'completed',
                'completed_on' => $on,
                'completed_by' => auth()->id() ?? $filing->completed_by,
            ])->save();

            $this->rollForward($filing->obligation, $filing->due_on, $on);

            $filing->emitDomainEvent('compliance.filing.completed', [
                'obligation_id' => $filing->compliance_obligation_id,
                'due_on' => $filing->due_on->toDateString(),
                'was_late' => $filing->wasLate(),
            ]);

            return $filing->refresh();
        });
    }

    /** The engine said no. The deadline is still there; only the attempt failed. */
    public function reject(ComplianceFiling $filing): ComplianceFiling
    {
        $filing->forceFill(['status' => 'rejected'])->save();

        $filing->emitDomainEvent('compliance.filing.rejected', [
            'obligation_id' => $filing->compliance_obligation_id,
        ]);

        return $filing->refresh();
    }

    /**
     * Sent back for something missing. Not a refusal — "no" and "not yet" are
     * different answers here for the same reason they are in the engine, and
     * a returned filing goes back to being prepared.
     *
     * A *rejected* filing comes back through here too. The deadline the
     * refusal was about is still there, and a statutory return that cannot be
     * corrected and refiled would leave the obligation permanently unmet —
     * so "refused" is a state to recover from, never a dead end.
     */
    public function returnToPreparer(ComplianceFiling $filing): ComplianceFiling
    {
        // Already back with the preparer: nothing to do, and not an error —
        // the listener may deliver the same verdict more than once.
        if ($filing->status === 'draft') {
            return $filing;
        }

        if (! in_array($filing->status, ['submitted', 'rejected'], true)) {
            throw new RuntimeException(
                'Only a filing awaiting sign-off or a refused one can go back to being prepared. This one is '.$filing->statusLabel().'.'
            );
        }

        $filing->forceFill(['status' => 'draft'])->save();

        return $filing->refresh();
    }

    /**
     * The evidence of having filed — and it is an ordinary document.
     *
     * Nothing is copied or re-stored. Retention, legal hold, versioning and
     * disposal already work on a BusinessDocument, and a certificate proving
     * a tax return was filed is the last thing that should sit outside them.
     */
    public function attachEvidence(
        ComplianceFiling $filing,
        BusinessDocument $document,
        ?User $actor = null,
    ): void {
        $this->linker->attach($document, $filing, 'supporting', $actor);
    }

    public function evidenceCount(ComplianceFiling $filing): int
    {
        return $this->linker->countFor($filing);
    }

    /** @param  array<string, mixed>  $details */
    protected function fill(ComplianceFiling $filing, array $details): void
    {
        $allowed = array_intersect_key($details, array_flip([
            'reference', 'period_label', 'amount', 'notes',
        ]));

        if ($allowed !== []) {
            $filing->forceFill($allowed)->save();
        }
    }

    /**
     * The next occurrence, or the end of a one-off duty.
     *
     * A one-off goes inactive rather than sitting on the register with no
     * date forever. Something with no next deadline and no way to be met
     * again is finished, and leaving it visible teaches people that rows on
     * this list can be ignored.
     */
    protected function rollForward(ComplianceObligation $obligation, Carbon $dueOn, Carbon $completedOn): void
    {
        $next = $obligation->nextDueAfter($dueOn, $completedOn);

        $obligation->forceFill([
            'next_due_on' => $next,
            'is_active' => $next !== null,
        ])->save();
    }
}
