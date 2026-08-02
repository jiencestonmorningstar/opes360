<?php

namespace App\Services\Payroll;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\PayslipLine;
use App\Models\User;
use App\Services\Accounting\Ledger;
use App\Services\Accounting\RecordsBusinessEvents;
use App\Support\CurrentCompany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A month's payroll, from the employee records to the books.
 *
 * ── Four states, one direction ──────────────────────────────────────────
 *
 *   draft     built and rebuilt as many times as it takes. Nothing outside
 *             this table knows it exists.
 *   approved  frozen and posted. From here the figures exist in the ledger,
 *             on the CNPS declaration and in the employees' hands, so they
 *             stop being editable. A mistake is corrected by voiding, which
 *             reverses the entry rather than erasing it.
 *   paid      the net has actually left the bank or the till.
 *   void      reversed.
 *
 * ── Why a run records its own rates ─────────────────────────────────────
 *
 * Because config/payroll.php will change and these payslips must not. The
 * rates are copied onto the run at approval, and anything that ever needs to
 * explain a past payslip computes with those rather than with today's.
 */
class PayrollRunner
{
    public function __construct(protected RecordsBusinessEvents $books) {}

    /** Start (or find) the draft for a month. One run per period, enforced. */
    public function open(string $period, ?User $actor = null): PayrollRun
    {
        $company = $this->company();
        $month = Carbon::parse($period)->startOfMonth();

        $existing = PayrollRun::query()->whereDate('period', $month->toDateString())->first();

        if ($existing !== null) {
            return $existing;
        }

        return PayrollRun::create([
            'company_id' => $company->id,
            'period' => $month->toDateString(),
            'label' => $month->translatedFormat('F Y'),
            // The last working day is the usual pay date; it is a default the
            // business overwrites, not a rule.
            'pay_date' => $month->copy()->endOfMonth()->toDateString(),
            'status' => 'draft',
            'currency' => $company->currency ?: 'XAF',
            'created_by' => $actor?->id,
        ]);
    }

    /**
     * (Re)compute every payslip in a draft run.
     *
     * Destructive by design: a rebuild throws the previous draft away rather
     * than reconciling it, because "the run half-reflects a change I made" is
     * a worse state than either of the two clean ones. Only drafts may be
     * rebuilt.
     */
    public function build(PayrollRun $run, ?User $actor = null): PayrollRun
    {
        if (! $run->isEditable()) {
            throw new RuntimeException('Only a draft payroll run can be rebuilt.');
        }

        $company = $this->company();
        $calculator = PayrollCalculator::fromConfig();
        $asOf = $run->period->copy()->endOfMonth()->toDateString();

        return DB::transaction(function () use ($run, $company, $calculator, $asOf) {
            PayslipLine::query()->whereIn(
                'payslip_id',
                Payslip::query()->where('payroll_run_id', $run->id)->pluck('id')
            )->delete();

            Payslip::query()->where('payroll_run_id', $run->id)->delete();

            $employees = Employee::query()
                ->where('status', 'active')
                ->with(['contracts', 'components', 'leaveRequests'])
                ->orderBy('last_name')
                ->get();

            $totals = ['gross' => 0.0, 'deductions' => 0.0, 'net' => 0.0, 'employer' => 0.0, 'count' => 0];

            foreach ($employees as $employee) {
                $contract = $employee->contractOn($asOf);

                // No contract covering this month means nothing to pay: a
                // person hired on the 3rd of next month is on the books but
                // not on this payroll.
                if ($contract === null || (float) $contract->base_salary <= 0) {
                    continue;
                }

                $result = $calculator->compute($this->inputFor($employee, $contract, $company, $asOf));

                $payslip = Payslip::create(array_merge($result->toColumns(), [
                    'company_id' => $company->id,
                    'payroll_run_id' => $run->id,
                    'employee_id' => $employee->id,
                    'employment_contract_id' => $contract->id,
                    'number' => $run->period->format('Ym').'-'.str_pad((string) ($totals['count'] + 1), 3, '0', STR_PAD_LEFT),
                    'currency' => $run->currency,
                    'status' => 'draft',
                    'payment_method' => $employee->payment_method,
                    'leave_days' => $this->leaveDaysIn($employee, $run),
                    // Who this was at the time. A corrected spelling three
                    // years later must not reissue history.
                    'snapshot' => [
                        'name' => $employee->name(),
                        'number' => $employee->number,
                        'job_title' => $contract->job_title ?: $employee->job_title,
                        'department' => $employee->department,
                        'cnps_number' => $employee->cnps_number,
                        'niu' => $employee->niu,
                        'contract_type' => $contract->type,
                        'hired_on' => $employee->hired_on?->toDateString(),
                    ],
                ]));

                foreach ($result->lines as $index => $line) {
                    PayslipLine::create([
                        'company_id' => $company->id,
                        'payslip_id' => $payslip->id,
                        'kind' => $line['kind'],
                        'code' => $line['code'],
                        'label' => $line['label'],
                        'base' => $line['base'],
                        'rate' => $line['rate'],
                        'amount' => $line['amount'],
                        'sort_order' => $index,
                    ]);
                }

                $totals['gross'] += $result->gross;
                $totals['deductions'] += $result->totalDeductions;
                $totals['net'] += $result->netPay;
                $totals['employer'] += $result->employerCharges;
                $totals['count']++;
            }

            $run->forceFill([
                'gross' => round($totals['gross'], 2),
                'employee_deductions' => round($totals['deductions'], 2),
                'net' => round($totals['net'], 2),
                'employer_charges' => round($totals['employer'], 2),
                'headcount' => $totals['count'],
            ])->save();

            return $run->refresh();
        });
    }

    /**
     * Freeze the run and post it.
     *
     * The entry is the whole month in one: the gross and the employer's
     * charges as costs, and what is owed to the staff, to the CNPS and to the
     * DGI as three separate debts, because they are settled on three
     * different dates to three different people.
     */
    public function approve(PayrollRun $run, ?User $actor = null): PayrollRun
    {
        $company = $this->company();

        return DB::transaction(function () use ($run, $company, $actor) {
            $locked = PayrollRun::query()->lockForUpdate()->findOrFail($run->id);

            if (! $locked->isDraft()) {
                throw new RuntimeException('This payroll run has already been approved.');
            }

            if ($locked->headcount === 0) {
                throw new RuntimeException('There is nothing to approve: this run has no payslips.');
            }

            $locked->forceFill([
                'status' => 'approved',
                'approved_by' => $actor?->id,
                'approved_at' => now(),
                'rates' => config('payroll'),
            ])->save();

            Payslip::query()->where('payroll_run_id', $locked->id)->update(['status' => 'approved']);

            $this->post($locked, $company, $actor);

            return $locked->refresh();
        });
    }

    /** The net has left the bank or the till. */
    public function markPaid(PayrollRun $run, array $data, ?User $actor = null): PayrollRun
    {
        $company = $this->company();

        return DB::transaction(function () use ($run, $data, $company, $actor) {
            $locked = PayrollRun::query()->lockForUpdate()->findOrFail($run->id);

            if (! $locked->isApproved()) {
                throw new RuntimeException('Approve the payroll run before recording its payment.');
            }

            $method = $data['method'] ?? 'bank';
            $paidOn = $data['paid_on'] ?? now()->toDateString();

            $locked->forceFill([
                'status' => 'paid',
                'paid_at' => now(),
                'pay_date' => $paidOn,
            ])->save();

            Payslip::query()->where('payroll_run_id', $locked->id)->update([
                'status' => 'paid',
                'paid_on' => $paidOn,
                'payment_reference' => $data['reference'] ?? null,
            ]);

            $this->postPayment($locked, $company, $method, $paidOn, $data['reference'] ?? null, $actor);

            return $locked->refresh();
        });
    }

    /**
     * Cancel a run.
     *
     * A draft is simply thrown away. An approved one is voided and its entry
     * reversed — never deleted, because by then the payslips have been handed
     * out and the ledger has to keep saying what it said.
     */
    public function void(PayrollRun $run, ?User $actor = null): PayrollRun
    {
        $company = $this->company();

        return DB::transaction(function () use ($run, $company, $actor) {
            if ($run->isPaid()) {
                throw new RuntimeException('This payroll has already been paid; reverse the payment first.');
            }

            $run->forceFill(['status' => 'void'])->save();

            Payslip::query()->where('payroll_run_id', $run->id)->update(['status' => 'void']);

            if ($entry = app(Ledger::class)->entryFor($company, $run)) {
                app(Ledger::class)->reverse($entry, $actor, 'Annulation paie '.$run->periodLabel());
            }

            return $run->refresh();
        });
    }

    /**
     * One employee's month, assembled from the contract in force and the
     * components attached to them.
     */
    protected function inputFor(Employee $employee, EmploymentContract $contract, Company $company, string $asOf): PayrollInput
    {
        $base = (float) $contract->base_salary;

        $allowances = [];
        $deductions = [];

        foreach ($employee->components as $component) {
            if (! $component->appliesOn($asOf)) {
                continue;
            }

            $value = $component->valueOn($base);

            if ($value <= 0) {
                continue;
            }

            if ($component->isAllowance()) {
                $allowances[] = [
                    'label' => $component->name,
                    'amount' => $value,
                    'taxable' => (bool) $component->taxable,
                    'cnps' => (bool) $component->cnps_liable,
                    'code' => 'component',
                ];

                continue;
            }

            $deductions[] = ['label' => $component->name, 'amount' => $value, 'code' => 'component'];
        }

        $settings = (array) ($company->payroll_settings ?? []);

        return new PayrollInput(
            baseSalary: $base,
            allowances: $allowances,
            deductions: $deductions,
            riskGroup: $company->cnps_risk_group ?: 'a',
            familyRegime: $company->cnps_family_regime ?: 'general',
            withholdTdl: (bool) ($settings['tdl'] ?? true),
            withholdRav: (bool) ($settings['rav'] ?? true),
        );
    }

    /** Approved leave falling inside the run's month, for the payslip. */
    protected function leaveDaysIn(Employee $employee, PayrollRun $run): float
    {
        $from = $run->period->copy()->startOfMonth();
        $to = $run->period->copy()->endOfMonth();

        return (float) $employee->leaveRequests
            ->where('status', 'approved')
            ->filter(fn ($leave) => $leave->starts_on->lte($to) && $leave->ends_on->gte($from))
            ->sum('days');
    }

    /**
     * The payroll entry.
     *
     * Debits are what the month cost: the gross, the allowances, the
     * employer's social charges and the employer's payroll taxes. Credits are
     * the three debts it created — the net owed to the staff, the CNPS owed
     * both sides of the pension, the state owed everything withheld — plus
     * whatever was recovered against a salary advance.
     */
    protected function post(PayrollRun $run, Company $company, ?User $actor): void
    {
        $slips = Payslip::query()->where('payroll_run_id', $run->id)->get();

        $sum = fn (string $column) => round((float) $slips->sum(fn (Payslip $p) => (float) $p->{$column}), 2);

        $wages = round($sum('base_salary') + $sum('overtime'), 2);
        $allowances = round($sum('taxable_allowances') + $sum('exempt_allowances'), 2);
        $socialCharges = round($sum('cnps_employer_pension') + $sum('cnps_employer_family') + $sum('cnps_employer_risk'), 2);
        $employerTaxes = round($sum('cfc_employer') + $sum('fne'), 2);

        $net = $sum('net_pay');
        $socialPayable = round($sum('cnps_employee') + $socialCharges, 2);
        $withheld = round(
            $sum('irpp') + $sum('cac') + $sum('cfc_employee') + $sum('tdl') + $sum('rav') + $employerTaxes,
            2
        );
        // Recovered from the payslip against something the employee already
        // had: a salary advance, a staff loan instalment.
        $recovered = round($sum('other_deductions') + $sum('advances'), 2);

        $this->books->recordQuietly(fn () => app(Ledger::class)->post(
            company: $company,
            journal: 'OD',
            entryDate: $run->period->copy()->endOfMonth()->toDateString(),
            lines: array_values(array_filter([
                ['account' => 'wages', 'debit' => $wages, 'narration' => 'Salaires bruts'],
                ['account' => 'staff_allowances', 'debit' => $allowances, 'narration' => 'Indemnités et primes'],
                ['account' => 'social_charges', 'debit' => $socialCharges, 'narration' => 'Charges sociales patronales'],
                ['account' => 'payroll_taxes', 'debit' => $employerTaxes, 'narration' => 'CFC et FNE (part patronale)'],
                ['account' => 'staff_payable', 'credit' => $net, 'narration' => 'Net à payer'],
                ['account' => 'social_payable', 'credit' => $socialPayable, 'narration' => 'CNPS'],
                ['account' => 'tax_withheld', 'credit' => $withheld, 'narration' => 'Impôts retenus à la source'],
                ['account' => 'staff_advances', 'credit' => $recovered, 'narration' => 'Avances récupérées'],
            ], fn (array $line) => ($line['debit'] ?? $line['credit'] ?? 0) > 0)),
            source: $run,
            narration: 'Paie '.$run->periodLabel(),
            reference: $run->period->format('Y-m'),
            actor: $actor,
        ));
    }

    /** Paying the staff: clear 422, credit whatever it came out of. */
    protected function postPayment(
        PayrollRun $run,
        Company $company,
        string $method,
        string $paidOn,
        ?string $reference,
        ?User $actor,
    ): void {
        $net = round((float) $run->net, 2);

        if ($net <= 0) {
            return;
        }

        $cashLike = in_array($method, ['cash', 'mobile_money'], true);

        $this->books->recordQuietly(fn () => app(Ledger::class)->post(
            company: $company,
            journal: $cashLike ? 'CA' : 'BQ',
            entryDate: $paidOn,
            lines: [
                ['account' => 'staff_payable', 'debit' => $net, 'narration' => 'Règlement des salaires'],
                ['account' => $cashLike ? 'cash' : 'bank', 'credit' => $net, 'narration' => $run->periodLabel()],
            ],
            /*
             * No source, so no idempotency from the ledger — the run already
             * sources the payroll entry, and reusing it here would make the
             * ledger swallow this one as a duplicate of that. What stops a
             * double payment instead is the caller: markPaid re-reads the run
             * under a lock and refuses anything that is not still `approved`,
             * so a second call cannot reach this line at all.
             */
            source: null,
            narration: 'Paiement paie '.$run->periodLabel(),
            reference: $reference ?? $run->period->format('Y-m'),
            actor: $actor,
        ));
    }

    protected function company(): Company
    {
        return app(CurrentCompany::class)->get()
            ?? throw new RuntimeException('Cannot run payroll without a current company.');
    }
}
