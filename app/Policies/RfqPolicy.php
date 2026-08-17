<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** See PurchaseRequisitionPolicy — procurement prefixes its abilities. */
class RfqPolicy extends CompanyScopedPolicy
{
    protected function group(): string
    {
        return 'procurement';
    }

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'rfq-view');
    }

    public function view(User $user, Model $rfq): bool
    {
        return $this->owns($rfq) && $this->allows($user, 'rfq-view');
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'rfq-manage');
    }

    public function update(User $user, Model $rfq): bool
    {
        return $this->owns($rfq) && $this->allows($user, 'rfq-manage');
    }

    public function delete(User $user, Model $rfq): bool
    {
        return $this->owns($rfq) && $this->allows($user, 'rfq-manage');
    }
}
