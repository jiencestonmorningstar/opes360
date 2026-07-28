<?php

namespace App\Policies;

use App\Models\Item;
use App\Models\User;

class ItemPolicy extends CompanyScopedPolicy
{
    protected function group(): string
    {
        return 'products';
    }

    public function adjustStock(User $user, Item $item): bool
    {
        return $this->owns($item) && $this->allows($user, 'adjust-stock');
    }
}
