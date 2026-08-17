<?php

namespace App\Policies;

class ContractPolicy extends ManagedGroupPolicy
{
    protected function group(): string
    {
        return 'contracts';
    }
}
