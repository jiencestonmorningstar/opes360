<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CostCentre;
use App\Models\ExpenseClaim;
use App\Models\ExpenseClaimLine;
use App\Models\ExpenseClaimReimbursement;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowInstance;
use App\Services\Accounting\Ledger;
use App\Services\Accounting\RecordsBusinessEvents;
use App\Services\Workflow\WorkflowEngine;
use App\Support\Accounting\ChartOfAccounts;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Employee expense claims: raising one, getting it agreed, paying it back.
 *
 * The approval is not here. `submit()` hands the claim to the shared
 * WorkflowEngine and reads its answer back; there is deliberately no
 * `approve()` on this service, because a second way to approve something is
 * how a product ends up with four approval engines and two of them wrong.
 *
 * The bookkeeping is in two halves, which is the whole reason a claim is not
 * just an Expense:
 *
 *   on approval      614/628/… D   the cost, charged where it was incurred
 *                    445       D   deductible TVA on the receipts
 *                    422       C   what the business now owes the employee
 *
 *   on reimbursement 422       D   the debt, cleared
 *                    571/521   C   cash actually handed over
 *
 * Between those two the employee is a creditor. 422 rather than 401 because
 * the creditor is staff — putting them in payables would mean the supplier
 * ageing report started chasing the person who bought the taxi.
 */
class ExpenseClaimService
{
    public function __construct(
        protected RecordsBusinessEvents $books,
        protected WorkflowEngine $engine,
    ) {}

    /**
     * @param  array{employee_id: string, title: string, claim_date: string, notes?: ?string,
     *               lines: array<int, array<string, mixed>>}  $data
     */
    public function create(array $data, ?User $actor = null): ExpenseClaim
    {
        $company = $this->company();

        $lines = array_values($data['lines'] ?? []);

        // A claim for nothing cannot be approved into a zero-value journal
        // entry, and Ledger refuses those — better to say so at the door than
        // to let an empty claim sit in somebody's approval queue.
        if ($lines === []) {
            throw new RuntimeException('A claim needs at least one expense on it.');
        }

        return DB::transaction(function () use ($company, $data, $lines, $actor) {
            $claim = new ExpenseClaim([
                'company_id' => $company->id,
                'employee_id' => $data['employee_id'],
                'title' => trim($data['title']),
                'claim_date' => $data['claim_date'],
                'currency' => $company->currency ?: 'XAF',
                // Set here rather than left to the column default: create()
                // does not read defaults back, so an unset status would be
                // null in memory and every isDraft() check would miss.
                'status' => 'draft',
                'subtotal' => 0,
                'vat_amount' => 0,
                'total' => 0,
                'amount_reimbursed' => 0,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor?->id,
            ]);

            $claim->save();

            foreach ($lines as $index => $line) {
                $this->addLine($claim, $line, $index);
            }

            $claim->load('lines');
            $claim->recompute();
            $claim->save();

            return $claim;
        });
    }

    /**
     * Hand the claim to the approval engine.
     *
     * Nothing here decides anything. The engine resolves the approvers, holds
     * the quorum and records the decisions; this method's only job is to stop
     * the same claim being put into two concurrent approvals.
     */
    public function submit(ExpenseClaim $claim, ?Workflow $workflow = null, ?User $actor = null): WorkflowInstance
    {
        if ($claim->isAwaitingApproval()) {
            throw new RuntimeException('This claim is already with an approver.');
        }

        if ($claim->isApproved()) {
            throw new RuntimeException('This claim has already been approved.');
        }

        $workflow ??= Workflow::defaultFor(ExpenseClaim::class);

        if ($workflow === null) {
            throw new RuntimeException('No approval path is defined for expense claims.');
        }

        $actor ??= $claim->creator;

        if ($actor === null) {
            throw new RuntimeException('A claim cannot be submitted without a submitter.');
        }

        $instance = $this->engine->start($claim, $workflow, $actor);

        $claim->forceFill(['status' => 'submitted', 'submitted_at' => now()])->save();

        return $instance;
    }

    /**
     * Charge the cost and record the debt, once the engine says yes.
     *
     * Called from the workflow.approved listener in the ordinary case, and
     * directly by anything that needs the books settled before it continues.
     * Safe to call twice: Ledger::post is idempotent per source, and the
     * status write is the same value.
     */
    public function postApproval(ExpenseClaim $claim, ?User $actor = null): void
    {
        if (! $claim->isApproved()) {
            throw new RuntimeException('That claim has not been approved.');
        }

        $company = $this->company();

        DB::transaction(function () use ($claim, $company, $actor) {
            if ($claim->status !== 'reimbursed') {
                $claim->forceFill([
                    'status' => 'approved',
                    'approved_at' => $claim->approved_at ?? now(),
                ])->save();
            }

            $claim->loadMissing('lines.account', 'employee');

            $this->books->recordQuietly(function () use ($claim, $company, $actor) {
                $net = round((float) $claim->subtotal, 2);
                $vat = round((float) $claim->vat_amount, 2);

                if ($net <= 0) {
                    return null;
                }

                $lines = [];

                foreach ($claim->lines as $line) {
                    $lines[] = [
                        'account' => $line->account ?? 'purchases',
                        'debit' => round((float) $line->amount, 2),
                        'narration' => $line->description,
                    ];
                }

                if ($vat > 0) {
                    $lines[] = ['account' => 'vat_deductible', 'debit' => $vat, 'narration' => 'TVA récupérable'];
                }

                $lines[] = [
                    'account' => 'staff_payable',
                    'credit' => round($net + $vat, 2),
                    'narration' => $claim->employee?->name() ?? $claim->title,
                ];

                return app(Ledger::class)->post(
                    company: $company,
                    journal: 'AC',
                    entryDate: $claim->claim_date->toDateString(),
                    lines: $lines,
                    source: $claim,
                    narration: 'Note de frais — '.$claim->title,
                    reference: $claim->number,
                    actor: $actor,
                );
            });
        });
    }

    /**
     * Pay the employee back, in whole or in part.
     *
     * @param  array{amount: float, method: string, paid_on?: ?string, reference?: ?string, note?: ?string}  $data
     */
    public function reimburse(ExpenseClaim $claim, array $data, ?User $actor = null): ExpenseClaimReimbursement
    {
        if (! $claim->isApproved()) {
            throw new RuntimeException('Only an approved claim can be reimbursed.');
        }

        $company = $this->company();

        // The debt has to exist in the books before it can be cleared. Doing
        // it here as well as on approval means a claim approved before this
        // listener was wired up is still reimbursable without a manual
        // journal.
        $this->postApproval($claim, $actor);

        return DB::transaction(function () use ($claim, $company, $data, $actor) {
            // Re-read under a lock: two people paying the same claim at once
            // must not both pass the "still owing" test and pay it twice.
            $locked = ExpenseClaim::query()->with('employee')->lockForUpdate()->findOrFail($claim->id);

            $amount = round((float) $data['amount'], 2);

            if ($amount <= 0) {
                throw new RuntimeException('A reimbursement must be for more than nothing.');
            }

            if ($amount - $locked->balance() > 0.005) {
                throw new RuntimeException('That is more than is owed on this claim.');
            }

            $reimbursement = ExpenseClaimReimbursement::create([
                'company_id' => $company->id,
                'expense_claim_id' => $locked->id,
                'amount' => $amount,
                'currency' => $locked->currency,
                'method' => $data['method'],
                'reference' => $data['reference'] ?? null,
                'paid_on' => $data['paid_on'] ?? now()->toDateString(),
                'note' => $data['note'] ?? null,
                'recorded_by' => $actor?->id,
            ]);

            $paid = round((float) $locked->amount_reimbursed + $amount, 2);

            $locked->forceFill([
                'amount_reimbursed' => $paid,
                'status' => $paid - (float) $locked->total >= -0.005 ? 'reimbursed' : $locked->status,
                'reimbursed_at' => $paid - (float) $locked->total >= -0.005 ? now() : null,
            ])->save();

            $this->postReimbursement($reimbursement, $locked, $company, $actor);

            return $reimbursement;
        });
    }

    /** Clearing the staff debt: debit 422, credit the till or the bank. */
    protected function postReimbursement(
        ExpenseClaimReimbursement $reimbursement,
        ExpenseClaim $claim,
        Company $company,
        ?User $actor,
    ): void {
        $this->books->recordQuietly(fn () => app(Ledger::class)->post(
            company: $company,
            journal: $reimbursement->isCashLike() ? 'CA' : 'BQ',
            entryDate: $reimbursement->paid_on->toDateString(),
            lines: [
                [
                    'account' => 'staff_payable',
                    'debit' => (float) $reimbursement->amount,
                    'narration' => $claim->employee?->name() ?? $claim->title,
                ],
                [
                    'account' => $reimbursement->isCashLike() ? 'cash' : 'bank',
                    'credit' => (float) $reimbursement->amount,
                    'narration' => $reimbursement->methodLabel(),
                ],
            ],
            source: $reimbursement,
            narration: 'Remboursement note de frais — '.$claim->title,
            reference: $reimbursement->reference,
            actor: $actor,
        ));
    }

    /** @param  array<string, mixed>  $data */
    protected function addLine(ExpenseClaim $claim, array $data, int $index): ExpenseClaimLine
    {
        $category = $data['category'] ?? 'other';

        $line = new ExpenseClaimLine([
            'company_id' => $claim->company_id,
            'expense_claim_id' => $claim->id,
            'cost_centre_id' => $this->costCentreId($claim, $data['cost_centre_id'] ?? null),
            'ledger_account_id' => $this->accountFor($category)?->id,
            'description' => trim((string) $data['description']),
            'category' => $category,
            'reference' => $data['reference'] ?? null,
            'incurred_on' => $data['incurred_on'] ?? $claim->claim_date,
            'amount' => round((float) $data['amount'], 2),
            'vat_rate' => (float) ($data['vat_rate'] ?? 0),
            'sort_order' => $index,
        ]);

        $line->recompute();
        $line->save();

        return $line;
    }

    /**
     * A cost centre has to be this company's own.
     *
     * Checked rather than trusted because the id arrives from a form field,
     * and an id from another tenant would silently allocate this company's
     * spending against a budget it cannot see — a leak that shows up only in
     * somebody else's budget report.
     */
    protected function costCentreId(ExpenseClaim $claim, ?string $id): ?string
    {
        if ($id === null) {
            return null;
        }

        $centre = CostCentre::query()
            ->withoutGlobalScopes()
            ->where('company_id', $claim->company_id)
            ->find($id);

        if ($centre === null) {
            throw new RuntimeException('That cost centre does not belong to this business.');
        }

        return $centre->id;
    }

    /** The company's own account for a category, seeded by ChartOfAccounts. */
    protected function accountFor(string $category): ?LedgerAccount
    {
        return LedgerAccount::query()
            ->where('number', ChartOfAccounts::accountForCategory($category))
            ->first();
    }

    protected function company(): Company
    {
        $company = app(CurrentCompany::class)->get();

        if ($company === null) {
            throw new RuntimeException('Cannot work with an expense claim without a current company.');
        }

        return $company;
    }
}
