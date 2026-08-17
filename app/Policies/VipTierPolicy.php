<?php

namespace App\Policies;

class VipTierPolicy extends ManagedGroupPolicy
{
    protected function group(): string
    {
        return 'vip';
    }
}
