<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Who may define an approval path.
 *
 * Not who may approve — being asked is itself the permission, and the engine
 * checks that for itself. Requiring a second one here would mean an approver
 * the engine had just assigned could not act.
 *
 * `manage` is narrow because whoever can edit a workflow can write themselves
 * a path with no approver in it, which is the same thing as being able to
 * spend the money.
 */
class WorkflowPolicy extends CompanyScopedPolicy
{
    protected function group(): string
    {
        return 'workflows';
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
