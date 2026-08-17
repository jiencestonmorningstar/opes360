<?php

namespace App\Policies;

use App\Models\User;

class AssetMaintenancePolicy extends CompanyScopedPolicy
{
    protected function group(): string
    {
        return 'assets';
    }

    /** Booking a service is `maintain`, the storeman's ability — not `create`. */
    public function create(User $user): bool
    {
        return $this->allows($user, 'maintain');
    }
}
