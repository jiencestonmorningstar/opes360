<?php

namespace App\Policies;

class JobOfferPolicy extends ManagedGroupPolicy
{
    protected function group(): string
    {
        return 'recruitment';
    }
}
