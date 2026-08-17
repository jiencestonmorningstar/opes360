<?php

namespace App\Policies;

class RiskPolicy extends ManagedGroupPolicy
{
    protected function group(): string
    {
        return 'risks';
    }
}
