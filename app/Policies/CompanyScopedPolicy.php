<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\User;
use App\Support\CurrentCompany;
use App\Support\PlanEntitlements;
use Illuminate\Database\Eloquent\Model;

/**
 * Base policy for every tenant-owned model.
 *
 * Two checks, always in this order:
 *
 *  1. Does the record belong to the company the user is acting as? The tenant
 *     scope already hides other companies' rows, but a policy that skipped this
 *     would authorise anything handed to it — including a record fetched through
 *     an unscoped query.
 *  2. Does the user's role in that company grant the permission?
 *
 * Subclasses only declare their permission prefix.
 */
abstract class CompanyScopedPolicy
{
    /** e.g. "sales" — combined with the ability into "sales.create". */
    abstract protected function group(): string;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'view');
    }

    public function view(User $user, Model $model): bool
    {
        return $this->owns($model) && $this->allows($user, 'view');
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'create');
    }

    public function update(User $user, Model $model): bool
    {
        return $this->owns($model) && $this->allows($user, 'update');
    }

    public function delete(User $user, Model $model): bool
    {
        return $this->owns($model) && $this->allows($user, 'delete');
    }

    protected function owns(Model $model): bool
    {
        $current = app(CurrentCompany::class)->id();

        return $current !== null && $model->getAttribute('company_id') === $current;
    }

    protected function allows(User $user, string $ability): bool
    {
        $company = app(CurrentCompany::class)->get();

        if (! $company instanceof Company) {
            return false;
        }

        $fullAbility = $this->group().'.'.$ability;

        return PlanEntitlements::allowsAbility($company, $fullAbility)
            && $user->hasPermissionIn($company, $fullAbility);
    }
}
