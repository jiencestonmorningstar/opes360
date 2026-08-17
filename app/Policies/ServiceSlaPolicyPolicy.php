<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * What the business has promised customers about response times. Writing it
 * is `manage-sla`, kept apart from the desk's day-to-day verbs so a manager
 * under pressure cannot relax the target they are measured against.
 */
class ServiceSlaPolicyPolicy extends CompanyScopedPolicy
{
    protected function group(): string
    {
        return 'service';
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'manage-sla');
    }

    public function update(User $user, Model $policy): bool
    {
        return $this->owns($policy) && $this->allows($user, 'manage-sla');
    }

    public function delete(User $user, Model $policy): bool
    {
        return $this->owns($policy) && $this->allows($user, 'manage-sla');
    }
}
