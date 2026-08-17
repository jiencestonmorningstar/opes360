<?php

namespace App\Policies;

class BillOfMaterialPolicy extends ManagedGroupPolicy
{
    protected function group(): string
    {
        return 'manufacturing';
    }
}
