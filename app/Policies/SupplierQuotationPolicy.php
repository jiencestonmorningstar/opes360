<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * A quotation belongs to the RFQ that invited it, so it carries the RFQ's
 * abilities. Awarding one is `rfq-award` and stays with the services that
 * ask it explicitly.
 */
class SupplierQuotationPolicy extends CompanyScopedPolicy
{
    protected function group(): string
    {
        return 'procurement';
    }

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'rfq-view');
    }

    public function view(User $user, Model $quotation): bool
    {
        return $this->owns($quotation) && $this->allows($user, 'rfq-view');
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'rfq-manage');
    }

    public function update(User $user, Model $quotation): bool
    {
        return $this->owns($quotation) && $this->allows($user, 'rfq-manage');
    }

    public function delete(User $user, Model $quotation): bool
    {
        return $this->owns($quotation) && $this->allows($user, 'rfq-manage');
    }
}
