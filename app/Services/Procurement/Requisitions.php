<?php

namespace App\Services\Procurement;

use App\Models\Company;
use App\Models\CostCentre;
use App\Models\PurchaseRequisition;
use App\Models\PurchaseRequisitionLine;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowInstance;
use App\Services\Workflow\WorkflowEngine;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Raising a request to buy something, and getting it agreed.
 *
 * There is deliberately no `approve()` and no `reject()` on this class.
 * `submit()` hands the requisition to the shared WorkflowEngine and the answer
 * comes back through `isApproved()`; `SyncApprovedRequisitions` caches that
 * answer into `status` so list screens do not each re-ask the engine. A second
 * approval mechanism living here is precisely what the master brief forbids,
 * and the reason the threshold rule ("over ten million needs the director")
 * appears nowhere in this file: it is a workflow step condition, typed by an
 * administrator, not code.
 */
class Requisitions
{
    public function __construct(protected WorkflowEngine $engine) {}

    /**
     * @param  array{title: string, needed_by?: ?string, justification?: ?string,
     *               department_id?: ?string, cost_centre_id?: ?string, notes?: ?string,
     *               lines: array<int, array<string, mixed>>}  $data
     */
    public function create(array $data, ?User $actor = null): PurchaseRequisition
    {
        $company = $this->company();

        $lines = array_values($data['lines'] ?? []);

        // A requisition for nothing cannot be costed, so it cannot pick an
        // approver by amount — better refused at the door than sat in
        // somebody's queue with no way to route it.
        if ($lines === []) {
            throw new RuntimeException('A requisition needs at least one thing on it.');
        }

        return DB::transaction(function () use ($company, $data, $lines, $actor) {
            $requisition = new PurchaseRequisition([
                'company_id' => $company->id,
                'number' => $this->nextNumber($company),
                'title' => trim($data['title']),
                'justification' => $data['justification'] ?? null,
                'needed_by' => $data['needed_by'] ?? null,
                'department_id' => $data['department_id'] ?? null,
                'cost_centre_id' => $this->costCentreId($company, $data['cost_centre_id'] ?? null),
                // Set here rather than left to the column default: create()
                // does not read defaults back, so an unset status would be
                // null in memory and every isOpen() check would miss.
                'status' => 'draft',
                'currency' => $company->currency ?: 'XAF',
                'estimated_total' => 0,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor?->id,
            ]);

            $requisition->save();

            foreach ($lines as $index => $line) {
                $this->addLine($requisition, $line, $index);
            }

            $requisition->load('lines');
            $requisition->recompute();
            $requisition->save();

            $requisition->emitDomainEvent('requisition.created', ['number' => $requisition->number]);

            return $requisition;
        });
    }

    /**
     * Hand the requisition to the approval engine.
     *
     * Nothing here decides anything. The engine resolves the approvers, holds
     * the quorum and records the decisions; this method's only job is to stop
     * the same requisition being put into two concurrent approvals.
     */
    public function submit(
        PurchaseRequisition $requisition,
        ?Workflow $workflow = null,
        ?User $actor = null,
    ): WorkflowInstance {
        if ($requisition->isAwaitingApproval()) {
            throw new RuntimeException('This requisition is already with an approver.');
        }

        if ($requisition->isApproved()) {
            throw new RuntimeException('This requisition has already been approved.');
        }

        $workflow ??= Workflow::defaultFor(PurchaseRequisition::class);

        if ($workflow === null) {
            throw new RuntimeException('No approval path is defined for purchase requisitions.');
        }

        $actor ??= $requisition->creator;

        if ($actor === null) {
            throw new RuntimeException('A requisition cannot be submitted without a submitter.');
        }

        /*
         * Marked submitted BEFORE the engine starts, not after. A requisition
         * under the approval threshold has its every step skipped, so the
         * engine finishes it — and fires the listener that writes "approved" —
         * inside start(); writing "submitted" afterwards would overwrite the
         * verdict, and the requisition would sit looking undecided while the
         * engine considered it done. Found by demo data, of all things.
         */
        $requisition->forceFill(['status' => 'submitted', 'submitted_at' => now()])->save();

        return $this->engine->start($requisition, $workflow, $actor);
    }

    /**
     * Cache the engine's verdict onto the requisition.
     *
     * Called from the `workflow.*` listener. It refuses to write "approved"
     * unless the engine actually says so, so a stray call cannot approve a
     * requisition that nobody signed.
     */
    public function syncVerdict(PurchaseRequisition $requisition, string $verdict): void
    {
        if ($verdict === 'approved' && ! $requisition->isApproved()) {
            throw new RuntimeException('The approval engine has not approved that requisition.');
        }

        // An ordered requisition keeps its status: the order is the later,
        // more informative fact, and a late-arriving event must not walk it
        // back to "approved".
        if ($requisition->status === 'ordered') {
            return;
        }

        $requisition->forceFill([
            'status' => $verdict,
            'approved_at' => $verdict === 'approved' ? ($requisition->approved_at ?? now()) : $requisition->approved_at,
        ])->save();
    }

    /** @param  array<string, mixed>  $data */
    protected function addLine(PurchaseRequisition $requisition, array $data, int $index): PurchaseRequisitionLine
    {
        $line = new PurchaseRequisitionLine([
            'company_id' => $requisition->company_id,
            'purchase_requisition_id' => $requisition->id,
            'item_id' => $data['item_id'] ?? null,
            'description' => trim((string) ($data['description'] ?? '')),
            'quantity' => (float) ($data['quantity'] ?? 1),
            'unit' => $data['unit'] ?? 'unit',
            'estimated_unit_price' => round((float) ($data['estimated_unit_price'] ?? 0), 2),
            'sort_order' => $index,
        ]);

        if ($line->description === '') {
            throw new RuntimeException('Every line needs to say what is being asked for.');
        }

        if ((float) $line->quantity <= 0) {
            throw new RuntimeException('A line has to ask for more than nothing.');
        }

        $line->recompute();
        $line->save();

        return $line;
    }

    /**
     * A cost centre has to be this company's own.
     *
     * Checked rather than trusted because the id arrives from a form field,
     * and an id from another tenant would allocate this company's spending
     * against a budget it cannot see — a leak visible only in somebody else's
     * budget report.
     */
    protected function costCentreId(Company $company, ?string $id): ?string
    {
        if ($id === null) {
            return null;
        }

        $centre = CostCentre::query()
            ->withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->find($id);

        if ($centre === null) {
            throw new RuntimeException('That cost centre does not belong to this business.');
        }

        return $centre->id;
    }

    /**
     * The next PR number for this company and year.
     *
     * Derived from the highest number already issued rather than a row count:
     * a soft-deleted requisition still holds its number under the unique
     * index, and counting would reissue it.
     */
    protected function nextNumber(Company $company): string
    {
        $prefix = 'PR-'.now()->format('Y').'-';

        $last = PurchaseRequisition::query()
            ->withoutGlobalScopes()
            ->withTrashed()
            ->where('company_id', $company->id)
            ->where('number', 'like', $prefix.'%')
            ->orderByDesc('number')
            ->value('number');

        $sequence = $last === null ? 1 : ((int) substr((string) $last, strlen($prefix))) + 1;

        return $prefix.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }

    protected function company(): Company
    {
        $company = app(CurrentCompany::class)->get();

        if ($company === null) {
            throw new RuntimeException('Cannot raise a requisition without a current company.');
        }

        return $company;
    }
}
