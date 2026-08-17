<?php

namespace App\Policies;

class InsuranceClaimPolicy extends ManagedGroupPolicy
{
    protected function group(): string
    {
        return 'insurance';
    }
}
