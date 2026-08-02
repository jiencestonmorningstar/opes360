<?php

namespace App\Livewire\Partners;

use App\Models\CardIssuance;
use App\Models\PartnerCommission;
use App\Models\PartnerPayout;
use App\Services\Partners\PartnerLedger;
use App\Support\CurrentCompany;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/**
 * What the partner has earned, what they have been charged, and the request to
 * be paid the difference.
 */
class Earnings extends Component
{
    public bool $requesting = false;

    public string $method = 'mtn';

    public string $destination = '';

    public function mount(): void
    {
        Gate::authorize('partners.view');
    }

    public function startRequest(): void
    {
        Gate::authorize('partners.withdraw');

        $this->resetValidation();
        $this->requesting = true;
    }

    public function cancel(): void
    {
        $this->requesting = false;
        $this->resetValidation();
    }

    public function requestPayout(PartnerLedger $ledger): void
    {
        Gate::authorize('partners.withdraw');

        $this->validate([
            'method' => ['required', 'in:mtn,orange,bank'],
            'destination' => ['required', 'string', 'max:120'],
        ], [
            'destination.required' => 'Where should the money go? A mobile money number, or an account.',
        ]);

        $company = app(CurrentCompany::class)->get();
        abort_if($company === null, 403);

        /*
         * The amount is recomputed here rather than taken from the form. A
         * balance shown a few minutes ago is not a promise, and the number the
         * partner is paid must be the number the ledger says right now.
         */
        $balance = $ledger->balance($company);

        if ($balance < (int) config('opes.partners.payout_minimum')) {
            $this->addError('destination', 'Your balance is below the minimum for a payout.');

            return;
        }

        PartnerPayout::create([
            'amount' => $balance,
            'currency' => config('opes.partners.currency', 'XAF'),
            'status' => 'requested',
            'method' => $this->method,
            'destination' => trim($this->destination),
            'requested_by' => auth()->id(),
        ]);

        $this->requesting = false;
        $this->reset('destination');

        session()->flash('status', 'Payout requested. We will confirm once it is sent.');
    }

    public function render(PartnerLedger $ledger): View
    {
        $company = app(CurrentCompany::class)->get();

        return view('livewire.partners.earnings', [
            'summary' => $ledger->summary($company),
            'commissions' => PartnerCommission::query()
                ->with('sourceCompany:id,name')
                ->latest()->limit(20)->get(),
            'issuances' => CardIssuance::query()
                ->with('client:id,name')
                ->where('status', 'billed')
                ->latest()->limit(20)->get(),
            'payouts' => PartnerPayout::query()->latest()->limit(10)->get(),
            'rate' => (float) config('opes.partners.commission_rate'),
        ])->layout('components.layouts.app', [
            'title' => 'Earnings',
            'active' => 'partners',
        ]);
    }
}
