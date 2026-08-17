<?php

namespace App\Policies;

class ServiceJobPolicy extends CompanyScopedPolicy
{
    protected function group(): string
    {
        return 'service';
    }
}
