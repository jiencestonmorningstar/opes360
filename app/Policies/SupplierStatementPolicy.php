<?php

namespace App\Policies;

class SupplierStatementPolicy extends ManagedGroupPolicy
{
    protected function group(): string
    {
        return 'payables';
    }
}
