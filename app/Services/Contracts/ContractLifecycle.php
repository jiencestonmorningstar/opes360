<?php

namespace App\Services\Contracts;

use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractObligation;
use App\Models\ContractRenewal;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowInstance;
use App\Services\Workflow\WorkflowEngine;
use App\Support\CurrentCompany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Raising a contract, getting it agreed, and the two ways it ends.
 *
 * There is no `approve()` here, and its absence is the design. A contract is
 * handed to the shared WorkflowEngine and its answer is read back; a second
 * approval path is precisely how a product ends up with four of them and two
 * disagreeing. `ActivateApprovedContracts` turns the engine's verdict into an
 * active contract, so nothing in this class ever has to ask who signed off.
 */
class ContractLifecycle
{
    public function __construct(protected WorkflowEngine $engine) {}

    /**
     * @param  array{title: string, contact_id?: ?string, direction?: string, type?: string,
     *               value?: ?float, currency?: ?string, starts_on: string, ends_on?: ?string,
     *               renewal_type?: string, renewal_term_months?: ?int, notice_period_days?: ?int,
     *               owner_id?: ?int, description?: ?string, notes?: ?string}  $data
     */
    public function raise(array $data, ?User $actor = null): Contract
    {
        $company = $this->company();

        $starts = Carbon::parse($data['starts_on']);
        $ends = isset($data['ends_on']) && $data['ends_on'] !== null
            ? Carbon::parse($data['ends_on'])
            : null;

        if ($ends !== null && $ends->lt($starts)) {
            throw new RuntimeException(
                'A contract cannot end before it starts. Check the dates on '.$data['title'].'.'
            );
        }

        /*
         * A contract that renews itself and gives no notice period is the
         * trap this whole feature exists to close: there is no date anyone
         * could be warned about, so it rolls over in silence forever. Refused
         * at the point of entry, where somebody still has the paper in front
         * of them and can read the number off it.
         */
        if (($data['renewal_type'] ?? 'none') === 'auto'
            && ($data['notice_period_days'] ?? null) === null
            && $ends !== null) {
            throw new RuntimeException(
                'An automatically renewing contract needs a notice period, otherwise nobody can '.
                'be told in time to stop it renewing.'
            );
        }

        $contract = Contract::create([
            'company_id' => $company->id,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'contact_id' => $data['contact_id'] ?? null,
            'direction' => $data['direction'] ?? 'inbound',
            'type' => $data['type'] ?? 'service',
            'value' => $data['value'] ?? null,
            'currency' => $data['currency'] ?? $company->currency,
            'starts_on' => $starts->toDateString(),
            'ends_on' => $ends?->toDateString(),
            'renewal_type' => $data['renewal_type'] ?? 'none',
            'renewal_term_months' => $data['renewal_term_months'] ?? null,
            'notice_period_days' => $data['notice_period_days'] ?? null,
            // Set here rather than left to the column default: Eloquent does
            // not read defaults back after an insert, so a contract created in
            // this request would report a null status to everything that
            // touched it before the next fetch.
            'status' => 'draft',
            'owner_id' => $data['owner_id'] ?? $actor?->id,
            'notes' => $data['notes'] ?? null,
            'created_by' => $actor?->id,
        ]);

        $contract->emitDomainEvent('contract.raised', [
            'contract_id' => $contract->id,
            'title' => $contract->title,
            'counterparty_id' => $contract->contact_id,
        ]);

        return $contract;
    }

    /** Hand it to the shared engine. The outcome comes back through Approvable. */
    public function submit(Contract $contract, User $submitter, ?Workflow $workflow = null): WorkflowInstance
    {
        if ($contract->status !== 'draft') {
            throw new RuntimeException(
                "{$contract->title} is already {$contract->status} and does not need approving again."
            );
        }

        $workflow ??= Workflow::defaultFor(Contract::class);

        if ($workflow === null) {
            throw new RuntimeException(
                'No approval workflow is set up for contracts. Define one, or activate the contract directly.'
            );
        }

        return $this->engine->start($contract, $workflow, $submitter);
    }

    /**
     * Put the contract into force.
     *
     * Refused while an approval is still running. Activating behind the
     * engine's back would leave a binding contract whose approval record says
     * it is still with somebody for a decision — and that record is what an
     * auditor reads.
     */
    public function activate(Contract $contract, ?User $actor = null, ?Carbon $on = null): Contract
    {
        if ($contract->isAwaitingApproval()) {
            throw new RuntimeException(
                "{$contract->title} is still waiting for approval. Let the approval finish before activating it."
            );
        }

        if ($contract->status === 'terminated') {
            throw new RuntimeException(
                "{$contract->title} was terminated. Raise a new contract rather than reviving this one."
            );
        }

        if ($contract->isActive()) {
            return $contract;
        }

        $contract->forceFill(['status' => 'active'])->save();

        $contract->emitDomainEvent('contract.activated', [
            'contract_id' => $contract->id,
            'title' => $contract->title,
            'ends_on' => $contract->ends_on?->toDateString(),
            'notice_by' => $contract->notice_by?->toDateString(),
        ]);

        return $contract;
    }

    /**
     * Extend it, and keep the record of the extension.
     *
     * The new end date and the renewal row are written together or not at all.
     * A contract sitting on a date with nothing saying how it got there is the
     * state this module exists to make impossible.
     *
     * @param  array{new_ends_on?: ?string, new_value?: ?float, method?: string, notes?: ?string,
     *               on?: ?string}  $data
     */
    public function renew(Contract $contract, array $data = [], ?User $actor = null): ContractRenewal
    {
        if ($contract->status !== 'active') {
            throw new RuntimeException(
                "Only a contract that is running can be renewed. {$contract->title} is {$contract->status}."
            );
        }

        if ($contract->ends_on === null) {
            throw new RuntimeException(
                "{$contract->title} has no end date, so there is nothing to extend. It runs until it is terminated."
            );
        }

        $newEnds = isset($data['new_ends_on']) && $data['new_ends_on'] !== null
            ? Carbon::parse($data['new_ends_on'])
            : $this->nextTermEnd($contract);

        if ($newEnds === null) {
            throw new RuntimeException(
                "{$contract->title} has no agreed renewal term, so a new end date has to be given."
            );
        }

        if ($newEnds->lte($contract->ends_on)) {
            throw new RuntimeException(
                'A renewal has to extend the contract. '.
                $newEnds->toDateString().' is not after '.$contract->ends_on->toDateString().'.'
            );
        }

        return DB::transaction(function () use ($contract, $newEnds, $data, $actor) {
            $renewal = ContractRenewal::create([
                'company_id' => $contract->company_id,
                'contract_id' => $contract->id,
                'previous_ends_on' => $contract->ends_on->toDateString(),
                'new_ends_on' => $newEnds->toDateString(),
                'previous_value' => $contract->value,
                'new_value' => $data['new_value'] ?? $contract->value,
                'method' => $data['method'] ?? 'negotiated',
                'renewed_on' => isset($data['on']) ? Carbon::parse($data['on'])->toDateString() : Carbon::today()->toDateString(),
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor?->id,
            ]);

            $contract->forceFill([
                'ends_on' => $newEnds->toDateString(),
                'value' => $data['new_value'] ?? $contract->value,
                'status' => 'active',
            ]);

            // notice_by follows from the new end date, and the model's saving
            // hook is what recomputes it. Setting it here as well would give
            // two writers for one derived value.
            $contract->save();

            $contract->emitDomainEvent('contract.renewed', [
                'contract_id' => $contract->id,
                'title' => $contract->title,
                'new_ends_on' => $newEnds->toDateString(),
                'method' => $renewal->method,
            ]);

            return $renewal;
        });
    }

    /**
     * End it early, or record that it has been ended.
     *
     * @param  array{reason?: ?string, on?: ?string}  $data
     */
    public function terminate(Contract $contract, array $data = [], ?User $actor = null): Contract
    {
        if ($contract->status !== 'active') {
            throw new RuntimeException(
                "Only a contract that is running can be terminated. {$contract->title} is {$contract->status}."
            );
        }

        $contract->forceFill([
            'status' => 'terminated',
            'terminated_on' => isset($data['on'])
                ? Carbon::parse($data['on'])->toDateString()
                : Carbon::today()->toDateString(),
            'termination_reason' => $data['reason'] ?? null,
            'terminated_by' => $actor?->id,
        ])->save();

        $contract->emitDomainEvent('contract.terminated', [
            'contract_id' => $contract->id,
            'title' => $contract->title,
            'reason' => $contract->termination_reason,
        ]);

        return $contract;
    }

    /**
     * Mark contracts whose end date has gone by as expired.
     *
     * Deliberately a sweep rather than something computed on read: a business
     * needs the register to say "expired" to everyone at once, including the
     * report somebody exported this morning, and a status that only changes
     * when a particular screen is opened does not do that.
     */
    public function expireLapsed(?Carbon $asOf = null): int
    {
        $asOf ??= Carbon::today();

        return Contract::query()
            ->live()
            ->whereNotNull('ends_on')
            ->whereDate('ends_on', '<', $asOf->toDateString())
            ->get()
            ->each(fn (Contract $contract) => $contract->forceFill(['status' => 'expired'])->save())
            ->count();
    }

    /** @param  array{owed_by?: string, title: string, description?: ?string, due_on?: ?string}  $data */
    public function addObligation(Contract $contract, array $data, ?User $actor = null): ContractObligation
    {
        return ContractObligation::create([
            'company_id' => $contract->company_id,
            'contract_id' => $contract->id,
            'owed_by' => ($data['owed_by'] ?? 'us') === 'them' ? 'them' : 'us',
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'due_on' => isset($data['due_on']) && $data['due_on'] !== null
                ? Carbon::parse($data['due_on'])->toDateString()
                : null,
            'created_by' => $actor?->id,
        ]);
    }

    public function completeObligation(
        ContractObligation $obligation,
        ?User $actor = null,
        ?Carbon $on = null,
        ?string $note = null,
    ): ContractObligation {
        if ($obligation->isDone()) {
            return $obligation;
        }

        $obligation->forceFill([
            'completed_on' => ($on ?? Carbon::today())->toDateString(),
            'completed_by' => $actor?->id,
            'completion_note' => $note,
        ])->save();

        return $obligation;
    }

    /**
     * Where the next term would end if the contract simply rolled over on the
     * terms already agreed.
     */
    protected function nextTermEnd(Contract $contract): ?Carbon
    {
        if ($contract->renewal_term_months === null || $contract->ends_on === null) {
            return null;
        }

        return $contract->ends_on->copy()->addMonths($contract->renewal_term_months);
    }

    protected function company(): Company
    {
        $company = app(CurrentCompany::class)->get();

        if ($company === null) {
            throw new RuntimeException('No company is selected, so there is nothing to raise a contract against.');
        }

        return $company;
    }
}
