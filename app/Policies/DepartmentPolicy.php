<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Reading the org chart and redrawing it are different jobs.
 *
 * `view` is wide — cost allocation, payroll reporting and document filing all
 * need to know the list exists. `manage` is narrow, because renaming a
 * department renames it everywhere it has ever appeared.
 */
class DepartmentPolicy extends CompanyScopedPolicy
{
    protected function group(): string
    {
        return 'departments';
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'manage');
    }

    public function update(User $user, Model $model): bool
    {
        return $this->owns($model) && $this->allows($user, 'manage');
    }

    public function delete(User $user, Model $model): bool
    {
        return $this->owns($model) && $this->allows($user, 'manage');
    }
}
