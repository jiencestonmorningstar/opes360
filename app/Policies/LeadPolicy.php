<?php

namespace App\Policies;

/**
 * Leads share the `deals` permission group on purpose: a lead is the front
 * half of the same pipeline, worked by the same people, and a role that may
 * see the board has no business being blind to where its deals come from.
 */
class LeadPolicy extends CompanyScopedPolicy
{
    protected function group(): string
    {
        return 'deals';
    }
}
