<?php

namespace App\Policies;

class FuelLogPolicy extends CompanyScopedPolicy
{
    protected function group(): string
    {
        return 'assets';
    }
}
