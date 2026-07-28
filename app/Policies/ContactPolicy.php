<?php

namespace App\Policies;

class ContactPolicy extends CompanyScopedPolicy
{
    protected function group(): string
    {
        return 'customers';
    }
}
