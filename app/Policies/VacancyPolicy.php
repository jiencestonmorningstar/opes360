<?php

namespace App\Policies;

class VacancyPolicy extends ManagedGroupPolicy
{
    protected function group(): string
    {
        return 'recruitment';
    }
}
