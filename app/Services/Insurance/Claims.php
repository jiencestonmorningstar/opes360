<?php

namespace App\Services\Insurance;

use App\Models\InsuranceClaim;
use App\Models\InsurancePolicy;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowInstance;
use App\Services\Workflow\WorkflowEngine;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * A claim's life: notified, assessed, and then the one decision with money in
 * it — settle or reject.
 *
 * There is no `approve()` here, and its absence is the design, on exactly the
 * reasoning ContractLifecycle records: settlement approval belongs to the
 * shared WorkflowEngine, the claim is Approvable, and the generic
 * `workflow.approved` listener (`SettleApprovedInsuranceClaims`) turns the
 * engine's verdict into a settled claim. A second approval path on the claim
 * itself is how a product ends up with two of them disagreeing — and a test
 * asserts the method stays absent.
 */
class Claims
{
    public function __construct(protected WorkflowEngine $engine) {}

    /**
     * First notification of loss.
     *
     * @param  array{incident_on: string, description: string, claim_number?: ?string,
     *               claimed_amount?: ?float, reported_on?: ?string}  $data
     */
    public function open(InsurancePolicy $policy, array $data, ?User $actor = null): InsuranceClaim
    {
        $incident = Carbon::parse($data['incident_on']);

        /*
         * Checked against the cover dates, not the policy's status — a claim
         * for an incident during cover is valid even if the policy has since
         * expired, and that is the common case for a claim reported late.
         */
        if ($incident->lt($policy->covers_from)
            || ($policy->covers_to !== null && $incident->gt($policy->covers_to))) {
            throw new RuntimeException(
                'The incident on '.$incident->toFormattedDateString().' falls outside this policy\'s cover ('
                .$policy->covers_from->toFormattedDateString().' to '
                .($policy->covers_to?->toFormattedDateString() ?? 'open').'). Check the date, or the policy.'
            );
        }

        $claim = InsuranceClaim::create([
            'company_id' => $policy->company_id,
            'insurance_policy_id' => $policy->id,
            'claim_number' => $data['claim_number'] ?? null,
            'incident_on' => $incident->toDateString(),
            'reported_on' => isset($data['reported_on'])
                ? Carbon::parse($data['reported_on'])->toDateString()
                : Carbon::today()->toDateString(),
            'description' => $data['description'],
            'claimed_amount' => $data['claimed_amount'] ?? null,
            'status' => 'fnol',
            'created_by' => $actor?->id,
        ]);

        $claim->emitDomainEvent('insurance.claim.opened', [
            'claim_id' => $claim->id,
            'policy_id' => $policy->id,
            'incident_on' => $claim->incident_on->toDateString(),
        ]);

        return $claim;
    }

    /**
     * The insurer or the assessor has looked at it.
     *
     * @param  array{claimed_amount?: ?float, notes?: ?string, claim_number?: ?string}  $data
     */
    public function assess(InsuranceClaim $claim, array $data = [], ?User $actor = null): InsuranceClaim
    {
        if ($claim->status !== 'fnol') {
            throw new RuntimeException(
                'Only a newly notified claim can be assessed. This one is already '.$claim->statusLabel().'.'
            );
        }

        $claim->forceFill(array_filter([
            'status' => 'assessed',
            'claimed_amount' => $data['claimed_amount'] ?? $claim->claimed_amount,
            'assessment_notes' => $data['notes'] ?? null,
            'claim_number' => $data['claim_number'] ?? $claim->claim_number,
        ], fn ($v) => $v !== null))->save();

        return $claim->fresh();
    }

    /**
     * Put a settlement offer to the approval workflow.
     *
     * The amount is written to the claim *before* the engine is asked, so the
     * approvers are approving a number, not a blank — and so the listener has
     * nothing to invent when the answer comes back yes.
     */
    public function submitSettlement(
        InsuranceClaim $claim,
        float $amount,
        User $submitter,
        ?Workflow $workflow = null,
    ): WorkflowInstance {
        if ($claim->status !== 'assessed') {
            throw new RuntimeException(
                'A settlement can only be put up on an assessed claim. This one is '.$claim->statusLabel().'.'
            );
        }

        if ($amount <= 0) {
            throw new RuntimeException('A settlement has to be an amount above zero.');
        }

        if ($claim->isAwaitingApproval()) {
            throw new RuntimeException('This settlement is already with somebody for a decision.');
        }

        $workflow ??= Workflow::defaultFor(InsuranceClaim::class);

        if ($workflow === null) {
            throw new RuntimeException(
                'No approval workflow is set up for claim settlements. Define one, or settle the claim directly.'
            );
        }

        $claim->forceFill(['settled_amount' => $amount])->save();

        return $this->engine->start($claim->fresh(), $workflow, $submitter);
    }

    /**
     * Close the claim as paid.
     *
     * The refusals are the engine's answer read back, never a status column of
     * this module's own: a settlement still with an approver cannot be
     * settled behind their back, and where a business has defined a workflow
     * for settlements, only the engine's yes opens this door.
     */
    public function settle(InsuranceClaim $claim, ?float $amount = null, ?User $actor = null): InsuranceClaim
    {
        if ($claim->isSettled()) {
            return $claim;
        }

        if (! in_array($claim->status, ['assessed'], true)) {
            throw new RuntimeException(
                'Only an assessed claim can be settled. This one is '.$claim->statusLabel().'.'
            );
        }

        if ($claim->isAwaitingApproval()) {
            throw new RuntimeException(
                'This settlement is still waiting for approval. Let the approval finish before settling it.'
            );
        }

        if (! $claim->isApproved() && Workflow::defaultFor(InsuranceClaim::class) !== null) {
            throw new RuntimeException(
                'Claim settlements here go through an approval workflow. Submit the settlement and let it come back approved.'
            );
        }

        $amount ??= $claim->settled_amount !== null ? (float) $claim->settled_amount : null;

        if ($amount === null || $amount <= 0) {
            throw new RuntimeException('There is no settlement amount on this claim to settle at.');
        }

        $claim->forceFill([
            'status' => 'settled',
            'settled_amount' => $amount,
            'settled_on' => Carbon::today()->toDateString(),
        ])->save();

        $claim->emitDomainEvent('insurance.claim.settled', [
            'claim_id' => $claim->id,
            'policy_id' => $claim->insurance_policy_id,
            'settled_amount' => $amount,
        ]);

        return $claim->fresh();
    }

    /** The insurer has said no, and the reason goes on the record. */
    public function reject(InsuranceClaim $claim, ?string $reason = null, ?User $actor = null): InsuranceClaim
    {
        if (! $claim->isOpen()) {
            throw new RuntimeException(
                'Only an open claim can be rejected. This one is already '.$claim->statusLabel().'.'
            );
        }

        if ($claim->isAwaitingApproval()) {
            throw new RuntimeException(
                'A settlement for this claim is with somebody for a decision. Cancel that approval first.'
            );
        }

        $claim->forceFill([
            'status' => 'rejected',
            'rejection_reason' => $reason,
        ])->save();

        $claim->emitDomainEvent('insurance.claim.rejected', [
            'claim_id' => $claim->id,
            'policy_id' => $claim->insurance_policy_id,
            'reason' => $reason,
        ]);

        return $claim->fresh();
    }
}
