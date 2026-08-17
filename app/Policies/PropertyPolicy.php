<?php

namespace App\Policies;

class PropertyPolicy extends ManagedGroupPolicy
{
    protected function group(): string
    {
        return 'estate';
    }
}
