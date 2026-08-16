<?php

namespace App\Livewire\Business;

use App\Models\Department;
use App\Models\Position;
use App\Support\CurrentCompany;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * The posts a business keeps, as opposed to the people filling them.
 *
 * The same screen as Departments, on purpose: a job title used to be whatever
 * somebody typed onto a staff file, so "Driver", "driver" and "Delivery Driver"
 * were three jobs. This is the list they are chosen from.
 *
 * The free-text `job_title` on each employee is still there and still what
 * payroll copies onto a payslip, so nothing here rewrites history.
 */
class Positions extends Component
{
    public string $title = '';

    public string $code = '';

    public string $grade = '';

    public ?string $departmentId = null;

    public ?string $editing = null;

    public bool $showArchived = false;

    public function mount(): void
    {
        Gate::authorize('positions.view');
    }

    public function rules(): array
    {
        return [
            'title' => [
                'required', 'string', 'max:120',
                Rule::unique('positions', 'title')
                    ->where('company_id', app(CurrentCompany::class)->id())
                    ->ignore($this->editing)
                    ->whereNull('deleted_at'),
            ],
            'code' => ['nullable', 'string', 'max:20'],
            'grade' => ['nullable', 'string', 'max:60'],
            'departmentId' => ['nullable', 'string', Rule::exists('departments', 'id')],
        ];
    }

    protected function messages(): array
    {
        return [
            'title.unique' => 'There is already a position with that title.',
        ];
    }

    public function save(): void
    {
        Gate::authorize('positions.manage');

        $this->validate();

        $attributes = [
            'title' => trim($this->title),
            'code' => $this->code ?: null,
            'grade' => $this->grade ?: null,
            'department_id' => $this->departmentId ?: null,
        ];

        $this->editing !== null
            ? Position::findOrFail($this->editing)->update($attributes)
            : Position::create($attributes);

        $this->reset(['title', 'code', 'grade', 'departmentId', 'editing']);
    }

    public function edit(string $id): void
    {
        Gate::authorize('positions.manage');

        $position = Position::findOrFail($id);

        $this->editing = $position->id;
        $this->title = (string) $position->title;
        $this->code = (string) $position->code;
        $this->grade = (string) $position->grade;
        $this->departmentId = $position->department_id;
    }

    public function cancel(): void
    {
        $this->reset(['title', 'code', 'grade', 'departmentId', 'editing']);
    }

    /**
     * Archived, never destroyed.
     *
     * A position is named on appraisals that have already happened and on the
     * staff file of everybody who ever held it. Deleting it would leave that
     * history reading "—", which is worse than a slightly longer list.
     */
    public function archive(string $id): void
    {
        Gate::authorize('positions.manage');

        Position::findOrFail($id)->update(['is_active' => false]);
    }

    public function restore(string $id): void
    {
        Gate::authorize('positions.manage');

        Position::findOrFail($id)->update(['is_active' => true]);
    }

    public function render(): View
    {
        $positions = Position::query()
            ->when(! $this->showArchived, fn ($query) => $query->active())
            ->with('department')
            // Leavers keep their position, so a plain count would report a
            // shop with two assistants as having had eleven.
            ->withCount(['employees as active_headcount' => fn ($query) => $query->where('status', 'active')])
            ->orderBy('sort_order')
            ->orderBy('title')
            ->get();

        return view('livewire.business.positions', [
            'positions' => $positions,
            'departments' => Department::query()->active()->orderBy('name')->get(),
        ])->layout('components.layouts.app', ['title' => 'Positions', 'active' => 'business']);
    }
}
