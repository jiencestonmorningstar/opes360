<?php

namespace App\Livewire\Team;

use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Support\CurrentCompany;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The staff file.
 *
 * Adding someone creates the person and their first contract in one step,
 * because an employee with no contract cannot be paid and a form that lets you
 * make one is a form that has produced a record nobody can use. Everything
 * else — later contracts, allowances, leave — happens on their own page.
 */
class Index extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $filter = 'active'; // active|all|ended

    public bool $adding = false;

    public string $firstName = '';

    public string $lastName = '';

    public string $jobTitle = '';

    public string $department = '';

    public string $phone = '';

    public string $employeeEmail = '';

    public string $cnpsNumber = '';

    public string $hiredOn = '';

    public string $contractType = 'cdi';

    public string $baseSalary = '';

    public string $contractEndsOn = '';

    public string $paymentMethod = 'cash';

    public function mount(): void
    {
        Gate::authorize('employees.view');

        $this->hiredOn = now()->toDateString();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedFilter(): void
    {
        $this->resetPage();
    }

    public function startAdding(): void
    {
        Gate::authorize('employees.create');

        $this->reset(['firstName', 'lastName', 'jobTitle', 'department', 'phone',
            'employeeEmail', 'cnpsNumber', 'baseSalary', 'contractEndsOn']);
        $this->resetValidation();
        $this->hiredOn = now()->toDateString();
        $this->contractType = 'cdi';
        $this->paymentMethod = 'cash';
        $this->adding = true;
    }

    public function cancel(): void
    {
        $this->adding = false;
        $this->resetValidation();
    }

    public function save(): void
    {
        Gate::authorize('employees.create');

        $this->validate([
            'firstName' => ['required', 'string', 'max:80'],
            'lastName' => ['required', 'string', 'max:80'],
            'jobTitle' => ['nullable', 'string', 'max:120'],
            'department' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'employeeEmail' => ['nullable', 'email', 'max:180'],
            'cnpsNumber' => ['nullable', 'string', 'max:40'],
            'hiredOn' => ['required', 'date'],
            'contractType' => ['required', 'in:'.implode(',', array_keys(config('payroll.contract_types')))],
            'baseSalary' => ['required', 'numeric', 'min:0'],
            'contractEndsOn' => ['nullable', 'date', 'after:hiredOn'],
            'paymentMethod' => ['required', 'in:'.implode(',', array_keys(Employee::PAYMENT_METHODS))],
        ], [
            'firstName.required' => 'Everyone needs a first name.',
            'lastName.required' => 'Everyone needs a last name.',
            'baseSalary.required' => 'What is this person paid a month?',
            'contractEndsOn.after' => 'A contract cannot end before it starts.',
        ]);

        $company = app(CurrentCompany::class)->get();

        DB::transaction(function () use ($company) {
            $employee = Employee::create([
                'company_id' => $company->id,
                'first_name' => trim($this->firstName),
                'last_name' => trim($this->lastName),
                'job_title' => $this->jobTitle ?: null,
                'department' => $this->department ?: null,
                'phone' => $this->phone ?: null,
                'email' => $this->employeeEmail ?: null,
                'cnps_number' => $this->cnpsNumber ?: null,
                'hired_on' => $this->hiredOn,
                'status' => 'active',
                'payment_method' => $this->paymentMethod,
                'created_by' => auth()->id(),
            ]);

            EmploymentContract::create([
                'company_id' => $company->id,
                'employee_id' => $employee->id,
                'type' => $this->contractType,
                'job_title' => $this->jobTitle ?: null,
                'starts_on' => $this->hiredOn,
                // A CDI has no end. A CDD without one is a CDI with extra steps.
                'ends_on' => $this->contractType === 'cdi' ? null : ($this->contractEndsOn ?: null),
                'base_salary' => (float) $this->baseSalary,
                'currency' => $company->currency ?: 'XAF',
                'status' => 'active',
            ]);
        });

        $this->adding = false;
        $this->resetPage();

        session()->flash('status', trim($this->firstName.' '.$this->lastName).' added to the team.');
    }

    public function render(): View
    {
        $employees = Employee::query()
            ->when($this->search !== '', function ($query) {
                $term = '%'.$this->search.'%';
                $query->where(fn ($q) => $q->where('first_name', 'like', $term)
                    ->orWhere('last_name', 'like', $term)
                    ->orWhere('job_title', 'like', $term)
                    ->orWhere('number', 'like', $term));
            })
            ->when($this->filter === 'active', fn ($q) => $q->where('status', 'active'))
            ->when($this->filter === 'ended', fn ($q) => $q->where('status', 'ended'))
            ->with(['contracts' => fn ($q) => $q->where('status', '!=', 'draft')])
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate(20);

        $headcount = Employee::query()->where('status', 'active')->count();

        /*
         * The monthly wage bill, from the contracts in force rather than from
         * the last payroll run: a business that has just hired two people
         * wants the number it is about to owe, not the one it owed in June.
         */
        $wageBill = (float) EmploymentContract::query()
            ->where('status', 'active')
            ->whereIn('employee_id', Employee::query()->where('status', 'active')->select('id'))
            ->sum('base_salary');

        return view('livewire.team.index', [
            'employees' => $employees,
            'headcount' => $headcount,
            'wageBill' => $wageBill,
            'currency' => app(CurrentCompany::class)->get()?->currency ?? 'XAF',
        ])->layout('components.layouts.app', ['title' => 'Team', 'active' => 'team']);
    }
}
