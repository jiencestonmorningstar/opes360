<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Artisans are part of the business's public identity, so they are governed by
 * the Business permission group rather than one of their own. That group has no
 * create/delete verbs — maintaining the directory *is* updating the business —
 * so both map onto `business.update`.
 */
class ArtisanPolicy extends CompanyScopedPolicy
{
    protected function group(): string
    {
        return 'business';
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'update');
    }

    public function delete(User $user, Model $model): bool
    {
        return $this->owns($model) && $this->allows($user, 'update');
    }
}
