<?php

namespace App\Policies;

class ProductionOrderPolicy extends ManagedGroupPolicy
{
    protected function group(): string
    {
        return 'manufacturing';
    }
}
