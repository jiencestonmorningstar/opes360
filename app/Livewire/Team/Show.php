<?php

namespace App\Livewire\Team;

use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Models\LeaveRequest;
use App\Models\Payslip;
use App\Models\SalaryComponent;
use App\Support\CurrentCompany;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/**
 * One person: who they are, what they are on, what they are paid and when they
 * were off.
 *
 * Four panels rather than four pages, because the questions a manager arrives
 * with — "what is she on now", "when does his CDD end", "how much leave has she
 * left" — are answered by looking at them together.
 */
class Show extends Component
{
    public Employee $employee;

    public string $panel = 'profile'; // profile|contracts|pay|leave

    // ── Profile ─────────────────────────────────────────────────────────
    public string $firstName = '';

    public string $lastName = '';

    public string $jobTitle = '';

    public string $department = '';

    public string $phone = '';

    public string $employeeEmail = '';

    public string $address = '';

    public string $nationalId = '';

    public string $cnpsNumber = '';

    public string $niu = '';

    public string $number = '';

    public string $paymentMethod = 'cash';

    public string $bankName = '';

    public string $bankAccount = '';

    public string $mobileMoneyNumber = '';

    public string $leaveOpeningBalance = '0';

    public string $notes = '';

    // ── Ending someone's employment ─────────────────────────────────────
    public bool $ending = false;

    public string $endDate = '';

    public string $endReason = '';

    // ── New contract ────────────────────────────────────────────────────
    public bool $addingContract = false;

    public string $contractType = 'cdi';

    public string $contractStartsOn = '';

    public string $contractEndsOn = '';

    public string $contractSalary = '';

    public string $contractTitle = '';

    public string $contractCategory = '';

    // ── New salary component ────────────────────────────────────────────
    public bool $addingComponent = false;

    public string $componentName = '';

    public string $componentKind = 'allowance';

    public string $componentAmount = '';

    public bool $componentTaxable = true;

    public bool $componentCnps = true;

    // ── New leave ───────────────────────────────────────────────────────
    public bool $addingLeave = false;

    public string $leaveType = 'annual';

    public string $leaveFrom = '';

    public string $leaveTo = '';

    public string $leaveDays = '';

    public string $leaveReason = '';

    public function mount(Employee $employee): void
    {
        Gate::authorize('employees.view');

        $this->employee = $employee;
        $this->fillProfile();
        $this->contractStartsOn = now()->toDateString();
        $this->leaveFrom = now()->toDateString();
        $this->leaveTo = now()->toDateString();
    }

    protected function fillProfile(): void
    {
        $this->firstName = (string) $this->employee->first_name;
        $this->lastName = (string) $this->employee->last_name;
        $this->jobTitle = (string) $this->employee->job_title;
        $this->department = (string) $this->employee->department;
        $this->phone = (string) $this->employee->phone;
        $this->employeeEmail = (string) $this->employee->email;
        $this->address = (string) $this->employee->address;
        $this->nationalId = (string) $this->employee->national_id;
        $this->cnpsNumber = (string) $this->employee->cnps_number;
        $this->niu = (string) $this->employee->niu;
        $this->number = (string) $this->employee->number;
        $this->paymentMethod = $this->employee->payment_method ?: 'cash';
        $this->bankName = (string) $this->employee->bank_name;
        $this->bankAccount = (string) $this->employee->bank_account;
        $this->mobileMoneyNumber = (string) $this->employee->mobile_money_number;
        $this->leaveOpeningBalance = (string) $this->employee->leave_opening_balance;
        $this->notes = (string) $this->employee->notes;
    }

    public function saveProfile(): void
    {
        Gate::authorize('employees.update');

        $this->validate([
            'firstName' => ['required', 'string', 'max:80'],
            'lastName' => ['required', 'string', 'max:80'],
            'jobTitle' => ['nullable', 'string', 'max:120'],
            'department' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'employeeEmail' => ['nullable', 'email', 'max:180'],
            'address' => ['nullable', 'string', 'max:180'],
            'nationalId' => ['nullable', 'string', 'max:40'],
            'cnpsNumber' => ['nullable', 'string', 'max:40'],
            'niu' => ['nullable', 'string', 'max:40'],
            'number' => ['nullable', 'string', 'max:40'],
            'paymentMethod' => ['required', 'in:'.implode(',', array_keys(Employee::PAYMENT_METHODS))],
            'bankName' => ['nullable', 'string', 'max:80'],
            'bankAccount' => ['nullable', 'string', 'max:60'],
            'mobileMoneyNumber' => ['nullable', 'string', 'max:40'],
            'leaveOpeningBalance' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->employee->update([
            'first_name' => trim($this->firstName),
            'last_name' => trim($this->lastName),
            'job_title' => $this->jobTitle ?: null,
            'department' => $this->department ?: null,
            'phone' => $this->phone ?: null,
            'email' => $this->employeeEmail ?: null,
            'address' => $this->address ?: null,
            'national_id' => $this->nationalId ?: null,
            'cnps_number' => $this->cnpsNumber ?: null,
            'niu' => $this->niu ?: null,
            'number' => $this->number ?: null,
            'payment_method' => $this->paymentMethod,
            'bank_name' => $this->bankName ?: null,
            'bank_account' => $this->bankAccount ?: null,
            'mobile_money_number' => $this->mobileMoneyNumber ?: null,
            'leave_opening_balance' => (float) $this->leaveOpeningBalance,
            'notes' => $this->notes ?: null,
        ]);

        session()->flash('status', 'Saved.');
    }

    public function startEnding(): void
    {
        Gate::authorize('employees.update');

        $this->resetValidation();
        $this->endDate = now()->toDateString();
        $this->endReason = '';
        $this->ending = true;
    }

    /**
     * Mark someone as having left.
     *
     * The record stays and the contracts stay; only the status changes. A
     * business asked for last year's payroll cannot have people missing from
     * it because they have since resigned.
     */
    public function endEmployment(): void
    {
        Gate::authorize('employees.update');

        $this->validate([
            'endDate' => ['required', 'date'],
            'endReason' => ['nullable', 'string', 'max:180'],
        ]);

        DB::transaction(function () {
            $date = $this->endDate;

            $this->employee->update([
                'status' => 'ended',
                'ended_on' => $date,
                'end_reason' => $this->endReason ?: null,
            ]);

            EmploymentContract::query()
                ->where('employee_id', $this->employee->id)
                ->where('status', 'active')
                ->update(['status' => 'ended', 'ended_on' => $date]);
        });

        $this->ending = false;

        $this->employee->refresh();

        session()->flash('status', $this->employee->name().' marked as having left.');
    }

    public function reinstate(): void
    {
        Gate::authorize('employees.update');

        $this->employee->update(['status' => 'active', 'ended_on' => null, 'end_reason' => null]);
        $this->employee->refresh();

        session()->flash('status', 'Back on the team. Add a contract to put them back on the payroll.');
    }

    // ── Contracts ───────────────────────────────────────────────────────

    public function startContract(): void
    {
        Gate::authorize('employees.update');

        $this->reset(['contractEndsOn', 'contractSalary', 'contractCategory']);
        $this->resetValidation();
        $this->contractStartsOn = now()->toDateString();
        $this->contractTitle = (string) $this->employee->job_title;
        $this->addingContract = true;
        $this->panel = 'contracts';
    }

    public function saveContract(): void
    {
        Gate::authorize('employees.update');

        $this->validate([
            'contractType' => ['required', 'in:'.implode(',', array_keys(config('payroll.contract_types')))],
            'contractStartsOn' => ['required', 'date'],
            'contractEndsOn' => ['nullable', 'date', 'after:contractStartsOn'],
            'contractSalary' => ['required', 'numeric', 'min:0'],
            'contractTitle' => ['nullable', 'string', 'max:120'],
            'contractCategory' => ['nullable', 'string', 'max:40'],
        ], [
            'contractEndsOn.after' => 'A contract cannot end before it starts.',
        ]);

        $company = app(CurrentCompany::class)->get();

        DB::transaction(function () use ($company) {
            /*
             * A new contract closes the one it replaces on the day before it
             * starts. Two active contracts would mean an employee paid twice,
             * and a raise is exactly this: a new contract from the 1st.
             */
            EmploymentContract::query()
                ->where('employee_id', $this->employee->id)
                ->where('status', 'active')
                ->update([
                    'status' => 'ended',
                    'ended_on' => Carbon::parse($this->contractStartsOn)->subDay()->toDateString(),
                ]);

            EmploymentContract::create([
                'company_id' => $company->id,
                'employee_id' => $this->employee->id,
                'type' => $this->contractType,
                'job_title' => $this->contractTitle ?: null,
                'category' => $this->contractCategory ?: null,
                'starts_on' => $this->contractStartsOn,
                'ends_on' => $this->contractType === 'cdi' ? null : ($this->contractEndsOn ?: null),
                'base_salary' => (float) $this->contractSalary,
                'currency' => $company->currency ?: 'XAF',
                'status' => 'active',
            ]);

            if ($this->contractTitle !== '') {
                $this->employee->update(['job_title' => $this->contractTitle]);
            }
        });

        $this->addingContract = false;
        $this->employee->refresh();

        session()->flash('status', 'Contract recorded. It applies from '.$this->contractStartsOn.'.');
    }

    // ── Allowances and deductions ───────────────────────────────────────

    public function startComponent(): void
    {
        Gate::authorize('employees.update');

        $this->reset(['componentName', 'componentAmount']);
        $this->resetValidation();
        $this->componentKind = 'allowance';
        $this->componentTaxable = true;
        $this->componentCnps = true;
        $this->addingComponent = true;
        $this->panel = 'pay';
    }

    public function saveComponent(): void
    {
        Gate::authorize('employees.update');

        $this->validate([
            'componentName' => ['required', 'string', 'max:80'],
            'componentKind' => ['required', 'in:allowance,deduction'],
            'componentAmount' => ['required', 'numeric', 'min:0.01'],
        ], [
            'componentName.required' => 'Give it a name — it appears on the payslip.',
        ]);

        SalaryComponent::create([
            'company_id' => app(CurrentCompany::class)->get()->id,
            'employee_id' => $this->employee->id,
            'name' => trim($this->componentName),
            'kind' => $this->componentKind,
            'amount' => (float) $this->componentAmount,
            // A deduction is never "taxable"; the flags only mean anything for
            // an allowance, and storing something misleading on the other half
            // is how a later query gets the wrong answer.
            'taxable' => $this->componentKind === 'allowance' ? $this->componentTaxable : false,
            'cnps_liable' => $this->componentKind === 'allowance' ? $this->componentCnps : false,
            'active' => true,
        ]);

        $this->addingComponent = false;
        $this->employee->refresh();

        session()->flash('status', 'Added. It appears on the next payslip.');
    }

    public function toggleComponent(string $id): void
    {
        Gate::authorize('employees.update');

        $component = SalaryComponent::query()->where('employee_id', $this->employee->id)->find($id);

        if ($component === null) {
            return;
        }

        $component->update(['active' => ! $component->active]);
        $this->employee->refresh();
    }

    // ── Leave ───────────────────────────────────────────────────────────

    public function startLeave(): void
    {
        Gate::authorize('leave.request');

        $this->reset(['leaveReason']);
        $this->resetValidation();
        $this->leaveType = 'annual';
        $this->leaveFrom = now()->toDateString();
        $this->leaveTo = now()->toDateString();
        $this->leaveDays = (string) LeaveRequest::workingDaysBetween($this->leaveFrom, $this->leaveTo);
        $this->addingLeave = true;
        $this->panel = 'leave';
    }

    /** Re-suggest the day count whenever either date moves. */
    public function updatedLeaveFrom(): void
    {
        $this->suggestLeaveDays();
    }

    public function updatedLeaveTo(): void
    {
        $this->suggestLeaveDays();
    }

    protected function suggestLeaveDays(): void
    {
        if ($this->leaveFrom !== '' && $this->leaveTo !== '') {
            $this->leaveDays = (string) LeaveRequest::workingDaysBetween($this->leaveFrom, $this->leaveTo);
        }
    }

    public function saveLeave(): void
    {
        Gate::authorize('leave.request');

        $this->validate([
            'leaveType' => ['required', 'in:'.implode(',', array_keys(config('payroll.leave.types')))],
            'leaveFrom' => ['required', 'date'],
            'leaveTo' => ['required', 'date', 'after_or_equal:leaveFrom'],
            'leaveDays' => ['required', 'numeric', 'min:0.5'],
            'leaveReason' => ['nullable', 'string', 'max:500'],
        ], [
            'leaveTo.after_or_equal' => 'Leave cannot end before it starts.',
        ]);

        LeaveRequest::create([
            'company_id' => app(CurrentCompany::class)->get()->id,
            'employee_id' => $this->employee->id,
            'type' => $this->leaveType,
            'starts_on' => $this->leaveFrom,
            'ends_on' => $this->leaveTo,
            'days' => (float) $this->leaveDays,
            'paid' => in_array($this->leaveType, (array) config('payroll.leave.paid', []), true),
            'deducts_balance' => in_array($this->leaveType, (array) config('payroll.leave.deducts_balance', []), true),
            'status' => 'pending',
            'reason' => $this->leaveReason ?: null,
            'requested_by' => auth()->id(),
        ]);

        $this->addingLeave = false;
        $this->employee->refresh();

        session()->flash('status', 'Leave requested.');
    }

    public function decideLeave(string $id, string $decision): void
    {
        Gate::authorize('leave.approve');

        $leave = LeaveRequest::query()->where('employee_id', $this->employee->id)->find($id);

        if ($leave === null || ! $leave->isPending()) {
            return;
        }

        $leave->update([
            'status' => $decision === 'approve' ? 'approved' : 'declined',
            'decided_by' => auth()->id(),
            'decided_at' => now(),
        ]);

        $this->employee->refresh();

        session()->flash('status', $decision === 'approve' ? 'Leave approved.' : 'Leave declined.');
    }

    public function render(): View
    {
        // Loaded here rather than lazily: strict mode forbids the lazy read,
        // and every panel needs its own slice.
        $this->employee->load([
            'contracts' => fn ($q) => $q->orderByDesc('starts_on'),
            'components' => fn ($q) => $q->orderBy('kind')->orderBy('name'),
            'leaveRequests' => fn ($q) => $q->orderByDesc('starts_on')->limit(24),
        ]);

        return view('livewire.team.show', [
            'contract' => $this->employee->activeContract(),
            'leaveBalance' => $this->employee->leaveBalance(),
            'payslips' => Payslip::query()
                ->where('employee_id', $this->employee->id)
                ->where('status', '!=', 'void')
                ->with('run:id,period,status')
                ->latest('created_at')
                ->limit(12)
                ->get(),
            'currency' => app(CurrentCompany::class)->get()?->currency ?? 'XAF',
        ])->layout('components.layouts.app', [
            'title' => $this->employee->name(),
            'active' => 'team',
        ]);
    }
}
