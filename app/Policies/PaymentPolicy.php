<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;

class PaymentPolicy extends CompanyScopedPolicy
{
    protected function group(): string
    {
        return 'payments';
    }

    /** Recording money is its own right, distinct from viewing the ledger. */
    public function record(User $user): bool
    {
        return $this->allows($user, 'record');
    }

    public function refund(User $user, Payment $payment): bool
    {
        return $this->owns($payment) && $this->allows($user, 'refund');
    }
}
