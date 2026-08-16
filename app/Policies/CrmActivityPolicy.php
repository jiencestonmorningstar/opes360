<?php

namespace App\Policies;

/**
 * Activities ride on the `deals` group: logging a call against a deal is
 * working the deal, not a separate privilege.
 */
class CrmActivityPolicy extends CompanyScopedPolicy
{
    protected function group(): string
    {
        return 'deals';
    }
}
