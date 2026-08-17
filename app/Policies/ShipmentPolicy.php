<?php

namespace App\Policies;

class ShipmentPolicy extends ManagedGroupPolicy
{
    protected function group(): string
    {
        return 'logistics';
    }
}
