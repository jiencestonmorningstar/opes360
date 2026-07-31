<?php

namespace App\Policies;

class EventPolicy extends CompanyScopedPolicy
{
    protected function group(): string
    {
        return 'events';
    }
}
