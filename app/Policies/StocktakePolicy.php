<?php

namespace App\Policies;

class StocktakePolicy extends CompanyScopedPolicy
{
    protected function group(): string
    {
        return 'products';
    }
}
