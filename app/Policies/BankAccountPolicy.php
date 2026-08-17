<?php

namespace App\Policies;

class BankAccountPolicy extends ManagedGroupPolicy
{
    protected function group(): string
    {
        return 'banking';
    }
}
