<?php

namespace App\Livewire\Business;

use App\Models\Department;
use App\Support\CurrentCompany;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * The org chart a business keeps for itself.
 *
 * Departments used to be whatever somebody typed into a box on the staff
 * file, which meant "Finance", "finance" and "Fin." were three departments.
 * This is the list they are chosen from.
 */
class Departments extends Component
{
    use AuthorizesRequests;

    public string $name = '';

    public string $code = '';

    public ?string $parentId = null;

    public ?string $editing = null;

    public bool $showArchived = false;

    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:120',
                Rule::unique('departments', 'name')
                    ->where('company_id', app(CurrentCompany::class)->id())
                    ->ignore($this->editing)
                    ->whereNull('deleted_at'),
            ],
            'code' => ['nullable', 'string', 'max:20'],
            'parentId' => ['nullable', 'string', Rule::exists('departments', 'id')],
        ];
    }

    protected function messages(): array
    {
        return [
            'name.unique' => 'There is already a department with that name.',
        ];
    }

    public function save(): void
    {
        $department = $this->editing !== null ? Department::findOrFail($this->editing) : null;

        $department !== null
            ? $this->authorize('update', $department)
            : $this->authorize('create', Department::class);

        $this->validate();

        if ($department !== null) {
            $department->update([
                'name' => $this->name,
                'code' => $this->code ?: null,
                'parent_id' => $this->parentId,
            ]);
        } else {
            Department::create([
                'name' => $this->name,
                'code' => $this->code ?: null,
                'parent_id' => $this->parentId,
            ]);
        }

        $this->reset(['name', 'code', 'parentId', 'editing']);
    }

    public function edit(string $id): void
    {
        $department = Department::findOrFail($id);

        $this->authorize('update', $department);

        $this->editing = $department->id;
        $this->name = $department->name;
        $this->code = (string) $department->code;
        $this->parentId = $department->parent_id;
    }

    public function cancel(): void
    {
        $this->reset(['name', 'code', 'parentId', 'editing']);
    }

    /**
     * Archived, never destroyed.
     *
     * A department name appears on payslips, documents and approvals that have
     * already happened. Deleting it would leave that history reading "—",
     * which is worse than a slightly longer list.
     */
    public function archive(string $id): void
    {
        $department = Department::findOrFail($id);

        $this->authorize('update', $department);

        $department->update(['is_active' => false]);
    }

    public function restore(string $id): void
    {
        $department = Department::findOrFail($id);

        $this->authorize('update', $department);

        $department->update(['is_active' => true]);
    }

    public function render(): View
    {
        $departments = Department::query()
            ->when(! $this->showArchived, fn ($query) => $query->active())
            ->withCount('employees')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return view('livewire.business.departments', [
            'departments' => $departments,
        ])->layout('components.layouts.app', ['title' => 'Departments', 'active' => 'business']);
    }
}
