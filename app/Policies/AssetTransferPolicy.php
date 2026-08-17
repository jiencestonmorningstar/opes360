<?php

namespace App\Policies;

use App\Models\User;

class AssetTransferPolicy extends CompanyScopedPolicy
{
    protected function group(): string
    {
        return 'assets';
    }

    /** Signing equipment out is `transfer`, the storeman's ability — not `create`. */
    public function create(User $user): bool
    {
        return $this->allows($user, 'transfer');
    }
}
