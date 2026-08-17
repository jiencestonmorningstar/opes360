<?php

namespace App\Policies;

class VehicleTripPolicy extends CompanyScopedPolicy
{
    protected function group(): string
    {
        return 'assets';
    }
}
