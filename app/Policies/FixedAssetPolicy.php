<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class FixedAssetPolicy extends CompanyScopedPolicy
{
    protected function group(): string
    {
        return 'assets';
    }

    /**
     * There is no `assets.delete` — leaving the register is a disposal, with
     * the write-off it implies, and `dispose` is the ability that names it.
     */
    public function delete(User $user, Model $asset): bool
    {
        return $this->owns($asset) && $this->allows($user, 'dispose');
    }
}
