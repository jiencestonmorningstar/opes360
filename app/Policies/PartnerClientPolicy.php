<?php

namespace App\Policies;

class PartnerClientPolicy extends ManagedGroupPolicy
{
    protected function group(): string
    {
        return 'partners';
    }
}
