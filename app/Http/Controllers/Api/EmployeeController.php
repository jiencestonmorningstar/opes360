<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\EmployeeResource;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * The staff file.
 *
 * Authorised by ability rather than by policy, because that is how this module
 * already works — there is no EmployeePolicy, and the screens ask
 * `employees.view` directly. Inventing a policy for the API alone would put
 * two answers to the same question in the codebase.
 *
 * Pay is not here. Salary lives on the employment contract and the payslip,
 * behind the payroll permissions, and the seeder's own note explains why: it
 * is the one thing in this system everybody is curious about and almost
 * nobody should see.
 */
class EmployeeController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('employees.view');

        $filters = $request->validate([
            'status' => ['sometimes', 'string', 'max:30'],
            'department' => ['sometimes', 'string', 'max:120'],
            'q' => ['sometimes', 'string', 'max:120'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $employees = Employee::query()
            ->when(isset($filters['status']), fn (Builder $q) => $q->where('status', $filters['status']))
            ->when(isset($filters['department']), fn (Builder $q) => $q->where('department', $filters['department']))
            ->when(isset($filters['q']), function (Builder $q) use ($filters) {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($filters['q'])).'%';

                $q->where(fn (Builder $inner) => $inner
                    ->where('first_name', 'like', $term)
                    ->orWhere('last_name', 'like', $term)
                    ->orWhere('number', 'like', $term));
            })
            ->orderBy('last_name')
            ->paginate($filters['per_page'] ?? 25);

        return EmployeeResource::collection($employees);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('employees.create');

        $data = $request->validate($this->rules());

        $employee = Employee::create($data + ['created_by' => $request->user()->id]);

        return EmployeeResource::make($employee)->response()->setStatusCode(201);
    }

    public function show(Employee $employee): EmployeeResource
    {
        $this->authorize('employees.view');

        return EmployeeResource::make($employee);
    }

    public function update(Request $request, Employee $employee): EmployeeResource
    {
        $this->authorize('employees.update');

        $employee->update($request->validate($this->rules(updating: true)));

        return EmployeeResource::make($employee->fresh());
    }

    /** @return array<string, array<int, mixed>> */
    private function rules(bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';

        return [
            'first_name' => [$required, 'string', 'max:80'],
            'last_name' => [$required, 'string', 'max:80'],
            'number' => ['nullable', 'string', 'max:40'],
            'job_title' => ['nullable', 'string', 'max:120'],
            'department' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:160'],
            'phone' => ['nullable', 'string', 'max:40'],
            'hired_on' => ['nullable', 'date'],
            'status' => ['sometimes', Rule::in(array_keys(Employee::STATUSES))],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
