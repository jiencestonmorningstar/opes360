<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Expense;
use App\Models\ExpensePayment;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Services\Accounting\Ledger;
use App\Services\Accounting\RecordsBusinessEvents;
use App\Support\Accounting\ChartOfAccounts;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Recording an expense, and settling it.
 *
 * The arithmetic and the bookkeeping live here rather than in the screen,
 * because an expense recorded by a console command, a future import, or the
 * payroll module posting its own charges has to reach the books the same way
 * one typed into a form does.
 */
class ExpenseRecorder
{
    public function __construct(protected RecordsBusinessEvents $books) {}

    /**
     * @param  array{supplier_id?: ?string, description: string, category: string, reference?: ?string,
     *               issue_date: string, due_date?: ?string, amount: float, vat_rate?: float,
     *               payment_method?: ?string, notes?: ?string}  $data
     */
    public function record(array $data, ?User $actor = null): Expense
    {
        $company = app(CurrentCompany::class)->get();

        if ($company === null) {
            throw new RuntimeException('Cannot record an expense without a current company.');
        }

        return DB::transaction(function () use ($company, $data, $actor) {
            $expense = new Expense([
                'supplier_id' => $data['supplier_id'] ?? null,
                'reference' => $data['reference'] ?? null,
                'description' => trim($data['description']),
                'category' => $data['category'],
                'issue_date' => $data['issue_date'],
                'due_date' => $data['due_date'] ?? null,
                'amount' => round((float) $data['amount'], 2),
                'vat_rate' => (float) ($data['vat_rate'] ?? 0),
                'currency' => $company->currency ?: 'XAF',
                'status' => 'recorded',
                'payment_method' => $data['payment_method'] ?? null,
                'notes' => $data['notes'] ?? null,
                'recorded_by' => $actor?->id,
                'ledger_account_id' => $this->accountFor($company, $data['category'])?->id,
            ]);

            $expense->recompute();
            $expense->save();

            /*
             * An expense with no due date was paid when it happened — a taxi,
             * a tank of fuel — so it is settled immediately rather than left
             * sitting in payables waiting for a payment nobody will record.
             */
            if (($data['due_date'] ?? null) === null && ($data['payment_method'] ?? null) !== null) {
                $this->settle($expense, [
                    'amount' => (float) $expense->total,
                    'method' => $data['payment_method'],
                    'paid_on' => $data['issue_date'],
                ], $actor);

                $expense->refresh();
            }

            $this->post($expense, $company, $actor);

            return $expense;
        });
    }

    /** Record a payment against an expense and post the settlement. */
    public function settle(Expense $expense, array $data, ?User $actor = null): ExpensePayment
    {
        $company = app(CurrentCompany::class)->get();

        if ($company === null) {
            throw new RuntimeException('Cannot settle an expense without a current company.');
        }

        return DB::transaction(function () use ($expense, $data, $actor, $company) {
            // Re-read under a lock: two people paying the same bill at once
            // must not both pass the "still owing" test.
            $locked = Expense::query()->with('supplier')->lockForUpdate()->findOrFail($expense->id);

            $amount = round((float) $data['amount'], 2);

            if ($amount <= 0) {
                throw new RuntimeException('A payment must be for more than nothing.');
            }

            if ($amount - $locked->balance() > 0.005) {
                throw new RuntimeException('That is more than is owing on this expense.');
            }

            $payment = ExpensePayment::create([
                'expense_id' => $locked->id,
                'amount' => $amount,
                'currency' => $locked->currency,
                'method' => $data['method'],
                'reference' => $data['reference'] ?? null,
                'paid_on' => $data['paid_on'] ?? now()->toDateString(),
                'note' => $data['note'] ?? null,
                'recorded_by' => $actor?->id,
            ]);

            $locked->forceFill(['amount_paid' => round((float) $locked->amount_paid + $amount, 2)])->save();

            $this->postSettlement($payment, $locked, $company, $actor);

            return $payment;
        });
    }

    /**
     * Cancel an expense recorded in error.
     *
     * Voided, not deleted, and its journal entry reversed rather than removed:
     * "what did the books say in March" has to keep having an answer.
     */
    public function void(Expense $expense, ?User $actor = null): void
    {
        $company = app(CurrentCompany::class)->get();

        if ($company === null) {
            return;
        }

        DB::transaction(function () use ($expense, $company, $actor) {
            $expense->loadMissing('account');

            if ($expense->payments()->exists()) {
                throw new RuntimeException('Reverse the payments against this expense before voiding it.');
            }

            $expense->forceFill(['status' => 'void'])->save();

            if ($entry = app(Ledger::class)->entryFor($company, $expense)) {
                app(Ledger::class)->reverse($entry, $actor, 'Annulation dépense');
            }
        });
    }

    /**
     * Charge the expense account, reclaim the deductible TVA, and credit
     * whatever is funding it.
     *
     * A direct expense paid on the spot credits cash or bank; a bill with terms
     * credits payables, and the settlement clears it later. Posting is
     * idempotent per expense — see Ledger::post.
     */
    protected function post(Expense $expense, Company $company, ?User $actor): void
    {
        // Strict mode forbids a lazy load, and a model that has been re-read
        // rather than just created is not exempt from it.
        $expense->loadMissing(['account', 'supplier']);

        $this->books->recordQuietly(function () use ($expense, $company, $actor) {
            $net = round((float) $expense->amount, 2);
            $vat = round((float) $expense->vat_amount, 2);

            if ($net <= 0) {
                return null;
            }

            $lines = [[
                'account' => $expense->account ?? 'purchases',
                'debit' => $net,
                'narration' => $expense->description,
            ]];

            if ($vat > 0) {
                $lines[] = ['account' => 'vat_deductible', 'debit' => $vat, 'narration' => 'TVA récupérable'];
            }

            // Settled on the spot, or owed. A bill is a bill until it is paid.
            $onCredit = $expense->due_date !== null || $expense->payment_method === null;

            $lines[] = [
                'account' => $onCredit
                    ? 'payables'
                    : (in_array($expense->payment_method, ['cash', 'mobile_money'], true) ? 'cash' : 'bank'),
                'credit' => round($net + $vat, 2),
                'narration' => $expense->supplier?->displayName() ?? $expense->categoryLabel(),
            ];

            return app(Ledger::class)->post(
                company: $company,
                journal: 'AC',
                entryDate: $expense->issue_date->toDateString(),
                lines: $lines,
                source: $expense,
                narration: $expense->description,
                reference: $expense->reference,
                actor: $actor,
            );
        });
    }

    /** Clearing a payable: debit the supplier, credit the till or the bank. */
    protected function postSettlement(ExpensePayment $payment, Expense $expense, Company $company, ?User $actor): void
    {
        // A direct expense already credited cash when it was recorded; posting
        // the settlement too would take the money out twice.
        if ($expense->due_date === null && $expense->payment_method !== null) {
            return;
        }

        $expense->loadMissing('supplier');

        $this->books->recordQuietly(fn () => app(Ledger::class)->post(
            company: $company,
            journal: $payment->isCashLike() ? 'CA' : 'BQ',
            entryDate: $payment->paid_on->toDateString(),
            lines: [
                ['account' => 'payables', 'debit' => (float) $payment->amount, 'narration' => $expense->supplier?->displayName()],
                ['account' => $payment->isCashLike() ? 'cash' : 'bank', 'credit' => (float) $payment->amount, 'narration' => $payment->methodLabel()],
            ],
            source: $payment,
            narration: 'Règlement fournisseur — '.$expense->description,
            reference: $payment->reference,
            actor: $actor,
        ));
    }

    /** The company's own account for a category, seeded by ChartOfAccounts. */
    protected function accountFor(Company $company, string $category): ?LedgerAccount
    {
        return LedgerAccount::query()
            ->where('number', ChartOfAccounts::accountForCategory($category))
            ->first();
    }
}
