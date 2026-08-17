<?php

namespace App\Policies;

class ComplianceObligationPolicy extends ManagedGroupPolicy
{
    protected function group(): string
    {
        return 'compliance';
    }
}
