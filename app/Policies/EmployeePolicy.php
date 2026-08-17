<?php

namespace App\Policies;

class EmployeePolicy extends CompanyScopedPolicy
{
    protected function group(): string
    {
        return 'employees';
    }
}
