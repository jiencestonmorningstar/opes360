<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Running a project and logging time on it are different jobs.
 *
 * `manage` is creating a project, setting its budget, and choosing its
 * client — the commercial terms. `log-time` is what everybody doing the work
 * needs, without being able to touch what the project is worth.
 */
class ProjectPolicy extends CompanyScopedPolicy
{
    protected function group(): string
    {
        return 'projects';
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

    public function logTime(User $user, Model $model): bool
    {
        return $this->owns($model) && $this->allows($user, 'log-time');
    }
}
