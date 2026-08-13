<?php

namespace App\Policies;

class DealPolicy extends CompanyScopedPolicy
{
    protected function group(): string
    {
        return 'deals';
    }
}
