<?php

namespace App\Policies;

class ServiceTicketPolicy extends CompanyScopedPolicy
{
    protected function group(): string
    {
        return 'service';
    }
}
