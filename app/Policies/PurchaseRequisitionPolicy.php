<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Procurement prefixes its abilities (`requisition-*`, `rfq-*`) because the
 * two halves of sourcing are done by different people, so the plain verbs
 * are remapped rather than inherited.
 */
class PurchaseRequisitionPolicy extends CompanyScopedPolicy
{
    protected function group(): string
    {
        return 'procurement';
    }

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'requisition-view');
    }

    public function view(User $user, Model $requisition): bool
    {
        return $this->owns($requisition) && $this->allows($user, 'requisition-view');
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'requisition-manage');
    }

    public function update(User $user, Model $requisition): bool
    {
        return $this->owns($requisition) && $this->allows($user, 'requisition-manage');
    }

    public function delete(User $user, Model $requisition): bool
    {
        return $this->owns($requisition) && $this->allows($user, 'requisition-manage');
    }
}
