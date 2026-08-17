<?php

namespace App\Policies;

class InsurancePolicyPolicy extends ManagedGroupPolicy
{
    protected function group(): string
    {
        return 'insurance';
    }
}
