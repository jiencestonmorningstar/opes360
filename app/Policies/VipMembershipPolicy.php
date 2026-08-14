<?php

namespace App\Policies;

class VipMembershipPolicy extends CompanyScopedPolicy
{
    protected function group(): string
    {
        return 'vip';
    }
}
