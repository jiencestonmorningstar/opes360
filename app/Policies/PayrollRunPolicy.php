<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Payroll's verbs are its own: `run` is the bookkeeping act that creates and
 * reworks a month, and a run is never deleted — it is voided, which is why
 * `delete` asks for `void` rather than an ability that does not exist.
 */
class PayrollRunPolicy extends CompanyScopedPolicy
{
    protected function group(): string
    {
        return 'payroll';
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'run');
    }

    public function update(User $user, Model $run): bool
    {
        return $this->owns($run) && $this->allows($user, 'run');
    }

    public function delete(User $user, Model $run): bool
    {
        return $this->owns($run) && $this->allows($user, 'void');
    }
}
