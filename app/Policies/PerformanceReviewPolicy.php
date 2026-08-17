<?php

namespace App\Policies;

class PerformanceReviewPolicy extends ManagedGroupPolicy
{
    protected function group(): string
    {
        return 'reviews';
    }
}
