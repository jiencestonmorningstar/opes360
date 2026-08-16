<?php

namespace App\Livewire\Insurance;

use App\Models\InsuranceClaim;
use App\Models\InsurancePolicy;
use App\Models\PolicyCommission;
use App\Services\Insurance\Claims;
use App\Services\Insurance\Policies;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use RuntimeException;

/**
 * One policy: the cover, the money both ways, and the claims under it.
 *
 * There is no approve button here and there never will be. A settlement goes
 * to the shared workflow and comes back approved; a second approval path on
 * the record itself is how a product ends up with two of them disagreeing.
 */
class Show extends Component
{
    public InsurancePolicy $policy;

    // ── Opening a claim ─────────────────────────────────────────────────
    public bool $claiming = false;

    public string $incidentOn = '';

    public string $claimDescription = '';

    public string $claimedAmount = '';

    // ── Settlement ──────────────────────────────────────────────────────
    public string $settlementAmount = '';

    // ── Cancellation ────────────────────────────────────────────────────
    public bool $cancelling = false;

    public string $cancelledOn = '';

    public string $cancellationReason = '';

    // ── Renewal ─────────────────────────────────────────────────────────
    public bool $renewing = false;

    public string $renewCoversTo = '';

    public string $renewPremium = '';

    // ── Endorsement ─────────────────────────────────────────────────────
    public bool $endorsing = false;

    public string $endorsementEffectiveOn = '';

    public string $endorsementDescription = '';

    public string $endorsementPremium = '';

    // ── Instalments ─────────────────────────────────────────────────────
    public string $instalmentCount = '';

    public function mount(InsurancePolicy $policy): void
    {
        Gate::authorize('insurance.view');

        $this->policy = $policy;
    }

    public function bind(): void
    {
        Gate::authorize('insurance.manage');

        try {
            app(Policies::class)->bind($this->policy, auth()->user());
        } catch (RuntimeException $e) {
            $this->addError('policy', $e->getMessage());

            return;
        }

        $this->refresh();
        $this->dispatch('toast', message: 'Bound — the cover is in force.');
    }

    public function invoicePremium(): void
    {
        Gate::authorize('insurance.manage');

        try {
            app(Policies::class)->invoicePremium($this->policy, auth()->user());
        } catch (RuntimeException $e) {
            $this->addError('policy', $e->getMessage());

            return;
        }

        $this->refresh();
        $this->dispatch('toast', message: 'Premium invoice drafted. Issue it from Sales when it is checked.');
    }

    public function recordCommission(): void
    {
        Gate::authorize('insurance.manage');

        try {
            app(Policies::class)->recordCommission($this->policy, null, auth()->user());
        } catch (RuntimeException $e) {
            $this->addError('policy', $e->getMessage());

            return;
        }

        $this->refresh();
        $this->dispatch('toast', message: 'Commission recorded against the insurer.');
    }

    public function invoiceCommission(string $id): void
    {
        Gate::authorize('insurance.manage');

        $commission = PolicyCommission::query()
            ->where('insurance_policy_id', $this->policy->id)
            ->findOrFail($id);

        try {
            app(Policies::class)->invoiceCommission($commission, auth()->user());
        } catch (RuntimeException $e) {
            $this->addError('policy', $e->getMessage());

            return;
        }

        $this->refresh();
        $this->dispatch('toast', message: 'Commission invoice drafted to the insurer.');
    }

    public function startRenewing(): void
    {
        Gate::authorize('insurance.manage');

        $this->resetValidation();

        // Prefill from the agreed term, so the ordinary case is one click —
        // and an unusual one is an edit, not a calculation.
        $this->renewCoversTo = $this->policy->covers_to !== null && $this->policy->renewal_term_months !== null
            ? $this->policy->covers_to->copy()->addDay()->addMonths($this->policy->renewal_term_months)->toDateString()
            : '';
        $this->renewPremium = $this->policy->premium !== null ? (string) $this->policy->premium : '';
        $this->renewing = true;
    }

    public function renew(): void
    {
        Gate::authorize('insurance.manage');

        $this->validate([
            'renewCoversTo' => ['nullable', 'date'],
            'renewPremium' => ['nullable', 'numeric', 'min:0'],
        ]);

        try {
            app(Policies::class)->renew($this->policy, [
                'new_covers_to' => $this->renewCoversTo ?: null,
                'new_premium' => $this->renewPremium === '' ? null : (float) $this->renewPremium,
                'method' => 'negotiated',
            ], auth()->user());
        } catch (RuntimeException $e) {
            $this->addError('renewing', $e->getMessage());

            return;
        }

        $this->renewing = false;
        $this->refresh();
        $this->dispatch('toast', message: 'Renewed — the new term is on the record, history kept.');
    }

    public function startEndorsing(): void
    {
        Gate::authorize('insurance.manage');

        $this->resetValidation();
        $this->endorsementEffectiveOn = now()->toDateString();
        $this->endorsementDescription = '';
        $this->endorsementPremium = $this->policy->premium !== null ? (string) $this->policy->premium : '';
        $this->endorsing = true;
    }

    public function endorse(): void
    {
        Gate::authorize('insurance.manage');

        $this->validate([
            'endorsementEffectiveOn' => ['required', 'date'],
            'endorsementDescription' => ['required', 'string', 'max:2000'],
            'endorsementPremium' => ['nullable', 'numeric', 'min:0'],
        ], [
            'endorsementDescription.required' => 'What changed on the cover?',
        ]);

        try {
            $endorsement = app(Policies::class)->endorse($this->policy, [
                'effective_on' => $this->endorsementEffectiveOn,
                'description' => $this->endorsementDescription,
                'new_premium' => $this->endorsementPremium === '' ? null : (float) $this->endorsementPremium,
            ], auth()->user());
        } catch (RuntimeException $e) {
            $this->addError('endorsing', $e->getMessage());

            return;
        }

        $this->endorsing = false;
        $this->refresh();
        $this->dispatch('toast', message: $endorsement->movesMoney()
            ? 'Endorsed — the premium adjustment is drafted in Sales.'
            : 'Endorsed, on the record.');
    }

    public function invoiceInstalments(): void
    {
        Gate::authorize('insurance.manage');

        $this->validate(['instalmentCount' => ['required', 'integer', 'min:2', 'max:12']], [
            'instalmentCount.required' => 'How many instalments?',
        ]);

        try {
            app(Policies::class)->invoicePremiumInstalments(
                $this->policy,
                auth()->user(),
                (int) $this->instalmentCount,
            );
        } catch (RuntimeException $e) {
            $this->addError('policy', $e->getMessage());

            return;
        }

        $this->instalmentCount = '';
        $this->refresh();
        $this->dispatch('toast', message: 'Instalment invoices drafted — the whole schedule, due dates set.');
    }

    public function startClaiming(): void
    {
        Gate::authorize('insurance.manage');

        $this->resetValidation();
        $this->incidentOn = now()->toDateString();
        $this->claimDescription = '';
        $this->claimedAmount = '';
        $this->claiming = true;
    }

    public function openClaim(): void
    {
        Gate::authorize('insurance.manage');

        $this->validate([
            'incidentOn' => ['required', 'date'],
            'claimDescription' => ['required', 'string', 'max:2000'],
            'claimedAmount' => ['nullable', 'numeric', 'min:0'],
        ], [
            'claimDescription.required' => 'What happened?',
        ]);

        try {
            app(Claims::class)->open($this->policy, [
                'incident_on' => $this->incidentOn,
                'description' => $this->claimDescription,
                'claimed_amount' => $this->claimedAmount === '' ? null : (float) $this->claimedAmount,
            ], auth()->user());
        } catch (RuntimeException $e) {
            $this->addError('claiming', $e->getMessage());

            return;
        }

        $this->claiming = false;
        $this->refresh();
        $this->dispatch('toast', message: 'Claim notified.');
    }

    public function assessClaim(string $id): void
    {
        Gate::authorize('insurance.manage');

        try {
            app(Claims::class)->assess($this->claim($id), [], auth()->user());
        } catch (RuntimeException $e) {
            $this->addError('claims', $e->getMessage());

            return;
        }

        $this->refresh();
        $this->dispatch('toast', message: 'Marked as assessed.');
    }

    public function submitSettlement(string $id): void
    {
        Gate::authorize('insurance.manage');

        $this->validate(['settlementAmount' => ['required', 'numeric', 'min:0.01']], [
            'settlementAmount.required' => 'What amount is being put up for approval?',
        ]);

        try {
            app(Claims::class)->submitSettlement(
                $this->claim($id),
                (float) $this->settlementAmount,
                auth()->user(),
            );
        } catch (RuntimeException $e) {
            $this->addError('claims', $e->getMessage());

            return;
        }

        $this->settlementAmount = '';
        $this->refresh();
        $this->dispatch('toast', message: 'Settlement sent for approval. It will show up in the approver\'s actions.');
    }

    /** The direct path, for a business with no settlement workflow defined. */
    public function settleClaim(string $id): void
    {
        Gate::authorize('insurance.settle');

        $this->validate(['settlementAmount' => ['required', 'numeric', 'min:0.01']]);

        try {
            app(Claims::class)->settle($this->claim($id), (float) $this->settlementAmount, auth()->user());
        } catch (RuntimeException $e) {
            $this->addError('claims', $e->getMessage());

            return;
        }

        $this->settlementAmount = '';
        $this->refresh();
        $this->dispatch('toast', message: 'Settled, and the amount on the record.');
    }

    public function rejectClaim(string $id): void
    {
        Gate::authorize('insurance.settle');

        try {
            app(Claims::class)->reject($this->claim($id), null, auth()->user());
        } catch (RuntimeException $e) {
            $this->addError('claims', $e->getMessage());

            return;
        }

        $this->refresh();
        $this->dispatch('toast', message: 'Rejected, on the record.');
    }

    public function startCancelling(): void
    {
        Gate::authorize('insurance.manage');

        $this->resetValidation();
        $this->cancelledOn = now()->toDateString();
        $this->cancellationReason = '';
        $this->cancelling = true;
    }

    public function cancel(): void
    {
        Gate::authorize('insurance.manage');

        $this->validate([
            'cancelledOn' => ['required', 'date'],
            'cancellationReason' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            app(Policies::class)->cancel($this->policy, [
                'on' => $this->cancelledOn,
                'reason' => $this->cancellationReason ?: null,
            ], auth()->user());
        } catch (RuntimeException $e) {
            $this->addError('cancelling', $e->getMessage());

            return;
        }

        $this->cancelling = false;
        $this->refresh();
        $this->dispatch('toast', message: 'Cancelled, and the date recorded.');
    }

    public function render(): View
    {
        Gate::authorize('insurance.view');

        $policy = $this->policy;

        return view('livewire.insurance.show', [
            'claims' => $policy->claims()->get(),
            'renewals' => $policy->renewals()->get(),
            'endorsements' => $policy->endorsements()->with('adjustmentDocument')->get(),
            'commissions' => $policy->commissions()->with('invoice')->get(),
            // The premium invoices are ordinary sales documents — this screen
            // links to them and reads their status; it never restates them.
            'invoices' => $policy->premiumInvoices(),
        ])->layout('components.layouts.app', [
            'title' => $policy->label(),
            'active' => 'insurance',
        ]);
    }

    protected function claim(string $id): InsuranceClaim
    {
        return InsuranceClaim::query()
            ->where('insurance_policy_id', $this->policy->id)
            ->findOrFail($id);
    }

    /** The status and the dates both move under us, so re-read them. */
    protected function refresh(): void
    {
        $this->policy = $this->policy->fresh();
    }
}
