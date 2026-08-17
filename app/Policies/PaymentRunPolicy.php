<?php

namespace App\Policies;

class PaymentRunPolicy extends ManagedGroupPolicy
{
    protected function group(): string
    {
        return 'payables';
    }
}
