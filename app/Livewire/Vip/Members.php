<?php

namespace App\Livewire\Vip;

use App\Models\Contact;
use App\Models\VipMembership;
use App\Models\VipTier;
use App\Services\VipMemberships;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use RuntimeException;

/**
 * Who is a member, and signing somebody up.
 *
 * Selling from here goes through VipMemberships, which raises the fee as a
 * real invoice — so a membership sold at the counter reaches the books by the
 * same route as one sold over the API, and there is only one place the rules
 * about extending an existing term live.
 */
class Members extends Component
{
    use AuthorizesRequests;
    use WithPagination;

    /** active|expired|all */
    #[Url]
    public string $filter = 'active';

    public ?string $sellTo = null;

    public ?string $sellTier = null;

    public function setFilter(string $filter): void
    {
        $this->filter = in_array($filter, ['active', 'expired', 'all'], true) ? $filter : 'active';
        $this->resetPage();
    }

    public function sell(VipMemberships $service): void
    {
        Gate::authorize('vip.sell');

        $this->validate([
            'sellTo' => ['required', 'string', 'exists:contacts,id'],
            'sellTier' => ['required', 'string', 'exists:vip_tiers,id'],
        ], [
            'sellTo.required' => 'Choose the customer joining.',
            'sellTier.required' => 'Choose the tier they are buying.',
        ]);

        try {
            $membership = $service->sell(
                Contact::findOrFail($this->sellTo),
                VipTier::findOrFail($this->sellTier),
                auth()->user(),
            );
        } catch (RuntimeException $e) {
            // A withdrawn tier, or no current company. The service's message is
            // written for the person at the counter, so it is shown as it is.
            $this->addError('sellTier', $e->getMessage());

            return;
        }

        $this->reset(['sellTo', 'sellTier']);

        session()->flash('status', sprintf(
            '%s is a %s member until %s. The invoice is on their account.',
            $membership->contact->displayName(),
            $membership->tier_name,
            $membership->ends_on->format('j M Y'),
        ));
    }

    public function cancel(string $id, VipMemberships $service): void
    {
        Gate::authorize('vip.manage');

        $service->cancel(
            VipMembership::findOrFail($id),
            'Cancelled from the members screen',
            auth()->user(),
        );

        session()->flash('status', 'Membership cancelled. The record is kept.');
    }

    public function render(): View
    {
        $this->authorize('viewAny', VipMembership::class);

        return view('livewire.vip.members', [
            'memberships' => VipMembership::query()
                ->with('contact')
                ->when($this->filter === 'active', fn (Builder $q) => $q->live())
                ->when($this->filter === 'expired', fn (Builder $q) => $q->whereDate('ends_on', '<', now()))
                ->latest('ends_on')
                ->paginate(20),
            'tiers' => VipTier::where('is_active', true)->orderBy('name')->get(),
            'contacts' => Contact::whereIn('type', ['customer', 'lead'])
                ->orderBy('name')
                ->get(['id', 'name', 'company_name']),
            'liveCount' => VipMembership::query()->live()->count(),
        ])->layout('components.layouts.app', ['title' => 'VIP members', 'active' => 'vip']);
    }
}
