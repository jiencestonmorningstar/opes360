<?php

namespace App\Policies;

class JobApplicationPolicy extends ManagedGroupPolicy
{
    protected function group(): string
    {
        return 'recruitment';
    }
}
