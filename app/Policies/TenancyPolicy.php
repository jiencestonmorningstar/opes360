<?php

namespace App\Policies;

class TenancyPolicy extends ManagedGroupPolicy
{
    protected function group(): string
    {
        return 'estate';
    }
}
