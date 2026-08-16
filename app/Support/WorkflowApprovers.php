<?php

namespace App\Support;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowStep;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Turns "the Finance manager" into a list of people, now.
 *
 * Resolution happens when a step is reached, never when it is written. A
 * workflow that stored a user id is wrong the day that person leaves, and
 * nobody finds out until an invoice has sat unapproved for a week.
 *
 * An unresolvable step returns nobody, and never falls back to "anyone" or to
 * the owner. A step that approves itself because its approver left is worse
 * than one that gets stuck: the stuck one gets noticed and fixed.
 */
class WorkflowApprovers
{
    /** @return Collection<int, User> */
    public function resolve(WorkflowStep $step, Model $subject): Collection
    {
        $company = app(CurrentCompany::class)->get();

        if ($company === null) {
            return collect();
        }

        $candidates = match ($step->approver_mode) {
            'user' => $this->byId($step->approver_user_id),
            'owner' => $this->byId($company->owner_id),
            'creator' => $this->byId($this->creatorId($subject)),
            'role' => $this->byRole($step->approver_role, $company->id),
            'department' => $this->byId(
                Department::find($step->approver_department_id)?->manager_id
            ),
            'manager' => $this->byId($this->submittersManagerId($subject)),
            default => collect(),
        };

        // Whoever is named must still be an active member of this company.
        return $candidates
            ->filter(fn (User $user) => $this->isActiveMember($user, $company->id))
            ->values();
    }

    /**
     * Which column holds "who raised this".
     *
     * Models disagree — expenses use `recorded_by`, documents `created_by` —
     * so a model may answer for itself via the Approvable trait rather than
     * this class knowing every table in the product.
     */
    protected function creatorId(Model $subject): mixed
    {
        if (method_exists($subject, 'workflowCreatorId')) {
            return $subject->workflowCreatorId();
        }

        return $subject->getAttribute('created_by');
    }

    protected function submittersManagerId(Model $subject): mixed
    {
        $creatorId = $this->creatorId($subject);

        if ($creatorId === null) {
            return null;
        }

        return Employee::query()->where('user_id', $creatorId)->first()?->departmentRecord?->manager_id;
    }

    /** @return Collection<int, User> */
    protected function byId(mixed $id): Collection
    {
        if ($id === null) {
            return collect();
        }

        $user = User::find($id);

        return $user === null ? collect() : collect([$user]);
    }

    /** @return Collection<int, User> */
    protected function byRole(?string $roleSlug, string $companyId): Collection
    {
        if ($roleSlug === null) {
            return collect();
        }

        $roleId = Role::query()->where('slug', $roleSlug)->value('id');

        if ($roleId === null) {
            return collect();
        }

        return User::query()
            ->whereHas('companies', fn ($query) => $query
                ->where('companies.id', $companyId)
                ->where('company_user.status', 'active')
                ->where('company_user.role_id', $roleId))
            ->get();
    }

    protected function isActiveMember(User $user, string $companyId): bool
    {
        return $user->companies()
            ->where('companies.id', $companyId)
            ->wherePivot('status', 'active')
            ->exists();
    }
}
