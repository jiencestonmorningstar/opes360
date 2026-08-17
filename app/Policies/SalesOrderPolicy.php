<?php

namespace App\Policies;

class SalesOrderPolicy extends ManagedGroupPolicy
{
    protected function group(): string
    {
        return 'orders';
    }
}
