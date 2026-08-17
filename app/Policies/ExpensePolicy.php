<?php

namespace App\Policies;

class ExpensePolicy extends CompanyScopedPolicy
{
    protected function group(): string
    {
        return 'expenses';
    }
}
