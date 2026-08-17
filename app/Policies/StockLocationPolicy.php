<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Locations live in the products group but belong to the stock-locations
 * module, and their one write ability is `manage-locations` — see
 * config/modules.php, which switches exactly that ability with the module.
 */
class StockLocationPolicy extends CompanyScopedPolicy
{
    protected function group(): string
    {
        return 'products';
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'manage-locations');
    }

    public function update(User $user, Model $location): bool
    {
        return $this->owns($location) && $this->allows($user, 'manage-locations');
    }

    public function delete(User $user, Model $location): bool
    {
        return $this->owns($location) && $this->allows($user, 'manage-locations');
    }
}
