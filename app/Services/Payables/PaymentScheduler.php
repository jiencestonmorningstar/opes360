<?php

namespace App\Services\Payables;

use App\Models\Company;
use App\Models\Expense;
use App\Models\PaymentRun;
use App\Models\PaymentRunItem;
use App\Models\User;
use App\Services\ExpenseRecorder;
use App\Support\CurrentCompany;
use App\Support\PaymentSchedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Turning a payment schedule into a payment run, and a run into money leaving.
 *
 * The schedule is a computation; this is where a person takes responsibility
 * for it. The split matters: a plan anybody can regenerate is free to change
 * every time the forecast moves, but once a run is approved it must stop
 * changing underneath the person who approved it.
 *
 * ── Approval before execution ──────────────────────────────────────────────
 *
 * A run cannot be executed straight from draft. Without that step a payment
 * run is a button that empties the bank account with nobody's name against it,
 * and the first time it is pressed by accident there is no way to say who
 * decided what.
 */
class PaymentScheduler
{
    public function __construct(protected ExpenseRecorder $expenses) {}

    /**
     * Build a draft run from a plan, taking only the lines the cash reaches.
     *
     * @param  array{scheduled_for?: ?string, method?: ?string, cash?: ?float, reference?: ?string, notes?: ?string}  $options
     */
    public function buildRun(PaymentSchedule $schedule, array $options = [], ?User $actor = null): PaymentRun
    {
        $company = $this->company();
        $plan = $schedule->plan($options['cash'] ?? null);

        return DB::transaction(function () use ($plan, $options, $actor, $company) {
            $run = PaymentRun::create([
                'company_id' => $company->id,
                'reference' => $options['reference'] ?? null,
                'scheduled_for' => $options['scheduled_for'] ?? $plan['pay_on'],
                'status' => PaymentRun::STATUS_DRAFT,
                'cash_available' => $plan['cash_available'],
                'currency' => $company->currency ?: 'XAF',
                'notes' => $options['notes'] ?? null,
                'created_by' => $actor?->id,
            ]);

            foreach ($plan['items'] as $item) {
                // Deferred lines are left out entirely rather than added as
                // zero-value rows. A run is a list of payments about to be
                // made; padding it with everything the business decided not to
                // pay makes the list unreadable at exactly the moment somebody
                // is checking it before releasing money.
                if ($item['decision'] === PaymentSchedule::DECISION_DEFER) {
                    continue;
                }

                PaymentRunItem::create([
                    'company_id' => $company->id,
                    'payment_run_id' => $run->id,
                    'expense_id' => $item['expense_id'],
                    'amount' => $item['scheduled'],
                    'method' => $options['method'] ?? 'bank',
                    'status' => PaymentRunItem::STATUS_PENDING,
                ]);
            }

            return $run->load('items.expense');
        });
    }

    /** Add a bill the plan did not choose. The business may know better. */
    public function addBill(PaymentRun $run, Expense $expense, ?float $amount = null, ?string $method = null): PaymentRunItem
    {
        $this->assertEditable($run);

        if ($run->items()->where('expense_id', $expense->id)->exists()) {
            throw new RuntimeException('That bill is already in this run.');
        }

        $amount = round($amount ?? $expense->balance(), 2);

        if ($amount <= 0 || $amount - $expense->balance() > 0.005) {
            throw new RuntimeException('That is more than is owing on this bill.');
        }

        return PaymentRunItem::create([
            'company_id' => $run->company_id,
            'payment_run_id' => $run->id,
            'expense_id' => $expense->id,
            'amount' => $amount,
            'method' => $method ?? 'bank',
            'status' => PaymentRunItem::STATUS_PENDING,
        ]);
    }

    /**
     * Take a line out of the run without deleting it.
     *
     * Kept rather than removed so the run still shows what was considered and
     * dropped. "Why was this supplier not paid" is the question a payment run
     * gets asked, and a deleted line cannot answer it.
     */
    public function skip(PaymentRunItem $item, ?string $note = null): PaymentRunItem
    {
        // loadMissing, not `$item->run`: implicit lazy loading is disabled
        // application-wide, so every relation has to be asked for.
        $item->loadMissing('run');
        $this->assertEditable($item->getRelation('run'));

        if ($item->isPaid()) {
            throw new RuntimeException('That payment has already gone out.');
        }

        $item->forceFill([
            'status' => PaymentRunItem::STATUS_SKIPPED,
            'note' => $note,
        ])->save();

        return $item;
    }

    public function approve(PaymentRun $run, ?User $actor = null): PaymentRun
    {
        if ($run->status !== PaymentRun::STATUS_DRAFT) {
            throw new RuntimeException('Only a draft run can be approved.');
        }

        $run->forceFill([
            'status' => PaymentRun::STATUS_APPROVED,
            'approved_by' => $actor?->id,
            'approved_at' => Carbon::now(),
        ])->save();

        return $run;
    }

    public function cancel(PaymentRun $run, ?string $reason = null): PaymentRun
    {
        if ($run->status === PaymentRun::STATUS_EXECUTED) {
            throw new RuntimeException('That run has already been paid; reverse the payments instead.');
        }

        $run->forceFill([
            'status' => PaymentRun::STATUS_CANCELLED,
            'notes' => $reason ?? $run->notes,
        ])->save();

        return $run;
    }

    /**
     * Pay the run.
     *
     * Settlement goes through ExpenseRecorder rather than writing payments
     * here, so a bill paid in a run and a bill paid by hand land in the books
     * the same way — one posting path, one place for the accounting to be
     * wrong or right.
     *
     * @return array{paid: int, total: float, failed: array<int, array{expense_id: string, reason: string}>}
     */
    public function execute(PaymentRun $run, ?User $actor = null): array
    {
        if ($run->status !== PaymentRun::STATUS_APPROVED) {
            throw new RuntimeException('A payment run must be approved before it can be paid.');
        }

        $paid = 0;
        $total = 0.0;
        $failed = [];

        DB::transaction(function () use ($run, $actor, &$paid, &$total, &$failed) {
            foreach ($run->items()->with('expense')->get() as $item) {
                if ($item->status !== PaymentRunItem::STATUS_PENDING) {
                    continue;
                }

                $expense = $item->expense;

                /*
                 * A bill can be settled by hand between the run being approved
                 * and being executed. Paying it again would hand the supplier
                 * money twice, so the line is skipped with its reason rather
                 * than failing the whole run — the other twenty suppliers
                 * should not wait on one already-paid invoice.
                 */
                if ($expense === null || $expense->isVoid() || $expense->isPaid()) {
                    $item->forceFill([
                        'status' => PaymentRunItem::STATUS_SKIPPED,
                        'note' => 'Already settled or voided before the run was paid.',
                    ])->save();

                    $failed[] = [
                        'expense_id' => $item->expense_id,
                        'reason' => 'Already settled or voided.',
                    ];

                    continue;
                }

                $amount = min(round((float) $item->amount, 2), $expense->balance());

                $payment = $this->expenses->settle($expense, [
                    'amount' => $amount,
                    'method' => $item->method ?: 'bank',
                    'paid_on' => $run->scheduled_for?->toDateString() ?? Carbon::now()->toDateString(),
                    'reference' => $run->reference,
                    'note' => 'Payment run',
                ], $actor);

                $item->forceFill([
                    'status' => PaymentRunItem::STATUS_PAID,
                    'amount' => $amount,
                    'expense_payment_id' => $payment->id,
                ])->save();

                $paid++;
                $total += $amount;
            }

            $run->forceFill(['status' => PaymentRun::STATUS_EXECUTED])->save();
        });

        return ['paid' => $paid, 'total' => round($total, 2), 'failed' => $failed];
    }

    protected function assertEditable(PaymentRun $run): void
    {
        if (! $run->isEditable()) {
            throw new RuntimeException('That run can no longer be changed.');
        }
    }

    protected function company(): Company
    {
        $company = app(CurrentCompany::class)->get();

        if ($company === null) {
            throw new RuntimeException('Cannot schedule payments without a current company.');
        }

        return $company;
    }
}
