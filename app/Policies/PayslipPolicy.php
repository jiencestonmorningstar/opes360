<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * A payslip is a product of the run, so it borrows the run's verbs: made and
 * remade under `run`, removed only by `void`.
 */
class PayslipPolicy extends CompanyScopedPolicy
{
    protected function group(): string
    {
        return 'payroll';
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'run');
    }

    public function update(User $user, Model $payslip): bool
    {
        return $this->owns($payslip) && $this->allows($user, 'run');
    }

    public function delete(User $user, Model $payslip): bool
    {
        return $this->owns($payslip) && $this->allows($user, 'void');
    }
}
