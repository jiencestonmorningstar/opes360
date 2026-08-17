<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Base for the module groups whose catalogue says `manage` instead of
 * spelling out create/update/delete (see Support\Permissions — contracts,
 * insurance, estate, logistics and friends all read `view, manage, …`).
 *
 * A subclass of CompanyScopedPolicy alone would ask for `contracts.create`,
 * an ability that does not exist and is therefore denied to everyone but the
 * Owner — the silent failure the Permissions header warns about. Mapping the
 * three write verbs onto `manage` here keeps every one of those policies a
 * one-liner while asking the gate a question it can actually answer.
 */
abstract class ManagedGroupPolicy extends CompanyScopedPolicy
{
    public function create(User $user): bool
    {
        return $this->allows($user, 'manage');
    }

    public function update(User $user, Model $model): bool
    {
        return $this->owns($model) && $this->allows($user, 'manage');
    }

    public function delete(User $user, Model $model): bool
    {
        return $this->owns($model) && $this->allows($user, 'manage');
    }
}
