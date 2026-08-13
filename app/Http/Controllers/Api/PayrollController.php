<?php

namespace App\Http\Controllers\Api;

use App\Models\PayrollRun;
use App\Models\Payslip;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Payroll, read only.
 *
 * Running a month, approving it and marking it paid are deliberately not here.
 * Approving commits the business to a month's wages and to the CNPS and IRPP
 * declarations that follow — the seeder calls that the owner's signature, and
 * the role catalogue keeps `payroll.approve` away from the accountant who runs
 * it. A signature is not a thing to hand to an API token.
 *
 * Reading is offered because the figures are wanted elsewhere — an accountant's
 * own spreadsheet, a bank payment file — and reading them changes nothing.
 */
class PayrollController extends ApiController
{
    public function runs(Request $request): JsonResponse
    {
        $this->authorize('payroll.view');

        $filters = $request->validate([
            'status' => ['sometimes', 'string', 'max:30'],
            'period' => ['sometimes', 'string', 'max:7'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $runs = PayrollRun::query()
            ->when(isset($filters['status']), fn (Builder $q) => $q->where('status', $filters['status']))
            ->when(isset($filters['period']), fn (Builder $q) => $q->where('period', $filters['period']))
            ->latest('period')
            ->paginate($filters['per_page'] ?? 25);

        $runs->getCollection()->transform(fn (PayrollRun $run) => [
            'id' => $run->id,
            'period' => $run->period,
            'label' => $run->label,
            'status' => $run->status,
            'pay_date' => $run->pay_date?->toDateString(),
            'headcount' => $run->headcount,
            'currency' => $run->currency,
            'gross' => (float) $run->gross,
            'employee_deductions' => (float) $run->employee_deductions,
            'net' => (float) $run->net,
            'employer_charges' => (float) $run->employer_charges,
            'approved_at' => $run->approved_at?->toIso8601String(),
            'paid_at' => $run->paid_at?->toIso8601String(),
        ]);

        return response()->json($runs->toArray());
    }

    /**
     * The payslips in one run.
     *
     * Scoped to a run rather than offered as a flat list, because "every
     * payslip this business has ever produced" is a question with no honest
     * use and a very obvious dishonest one.
     */
    public function payslips(Request $request, PayrollRun $run): JsonResponse
    {
        $this->authorize('payroll.view');

        $payslips = Payslip::query()
            ->with('employee')
            ->where('payroll_run_id', $run->id)
            ->get()
            ->map(fn (Payslip $slip) => [
                'id' => $slip->id,
                'number' => $slip->number,
                'employee_id' => $slip->employee_id,
                'employee_name' => trim(($slip->employee?->first_name ?? '').' '.($slip->employee?->last_name ?? '')),
                'status' => $slip->status,
                'currency' => $slip->currency,
                'base_salary' => (float) $slip->base_salary,
                'gross' => (float) $slip->gross,
                'total_deductions' => (float) $slip->total_deductions,
                'net_pay' => (float) $slip->net_pay,
                'employer_charges' => (float) $slip->employer_charges,
                'total_cost' => (float) $slip->total_cost,
                'paid_on' => $slip->paid_on?->toDateString(),
            ]);

        return response()->json(['data' => $payslips->values()]);
    }
}
