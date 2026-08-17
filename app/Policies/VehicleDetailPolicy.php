<?php

namespace App\Policies;

class VehicleDetailPolicy extends CompanyScopedPolicy
{
    protected function group(): string
    {
        return 'assets';
    }
}
