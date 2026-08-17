<?php

namespace App\Policies;

class TripManifestPolicy extends ManagedGroupPolicy
{
    protected function group(): string
    {
        return 'logistics';
    }
}
