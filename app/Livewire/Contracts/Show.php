<?php

namespace App\Livewire\Contracts;

use App\Models\Contract;
use App\Models\ContractObligation;
use App\Services\Contracts\ContractLifecycle;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use RuntimeException;

/**
 * One agreement: what was agreed, what each side still owes, and how it got to
 * the term it is on.
 *
 * There is no approve button here and there never will be. A contract goes to
 * the shared workflow and comes back approved; a second approval path on the
 * record itself is how a product ends up with two of them disagreeing.
 */
class Show extends Component
{
    public Contract $contract;

    // ── Renewal ─────────────────────────────────────────────────────────
    public bool $renewing = false;

    public string $newEndsOn = '';

    public string $newValue = '';

    public string $renewalMethod = 'negotiated';

    public string $renewalNotes = '';

    // ── Termination ─────────────────────────────────────────────────────
    public bool $terminating = false;

    public string $terminatedOn = '';

    public string $terminationReason = '';

    // ── Obligations ─────────────────────────────────────────────────────
    public bool $addingObligation = false;

    public string $owedBy = 'us';

    public string $obligationTitle = '';

    public string $obligationDueOn = '';

    public string $obligationDescription = '';

    public function mount(Contract $contract): void
    {
        Gate::authorize('contracts.view');

        $this->contract = $contract;
    }

    public function submit(): void
    {
        Gate::authorize('contracts.manage');

        try {
            app(ContractLifecycle::class)->submit($this->contract, auth()->user());
        } catch (RuntimeException $e) {
            $this->addError('contract', $e->getMessage());

            return;
        }

        $this->refresh();
        $this->dispatch('toast', message: 'Sent for approval.');
    }

    public function startRenewing(): void
    {
        Gate::authorize('contracts.renew');

        $this->resetValidation();
        // Left blank on purpose: empty means "roll it on by the term already
        // agreed", which is the answer for most renewals and the one the
        // service can work out without anybody retyping a date.
        $this->newEndsOn = '';
        $this->newValue = $this->contract->value === null ? '' : (string) $this->contract->value;
        $this->renewalMethod = $this->contract->autoRenews() ? 'auto' : 'negotiated';
        $this->renewalNotes = '';
        $this->renewing = true;
    }

    public function renew(): void
    {
        Gate::authorize('contracts.renew');

        $this->validate([
            'newEndsOn' => ['nullable', 'date'],
            'newValue' => ['nullable', 'numeric', 'min:0'],
            'renewalMethod' => ['required', 'in:auto,negotiated'],
            'renewalNotes' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            app(ContractLifecycle::class)->renew($this->contract, [
                'new_ends_on' => $this->newEndsOn ?: null,
                'new_value' => $this->newValue === '' ? null : (float) $this->newValue,
                'method' => $this->renewalMethod,
                'notes' => $this->renewalNotes ?: null,
            ], auth()->user());
        } catch (RuntimeException $e) {
            $this->addError('renewing', $e->getMessage());

            return;
        }

        $this->renewing = false;
        $this->refresh();
        $this->dispatch('toast', message: 'Renewed, and the extension is on the record.');
    }

    public function startTerminating(): void
    {
        Gate::authorize('contracts.terminate');

        $this->resetValidation();
        $this->terminatedOn = now()->toDateString();
        $this->terminationReason = '';
        $this->terminating = true;
    }

    public function terminate(): void
    {
        Gate::authorize('contracts.terminate');

        $this->validate([
            'terminatedOn' => ['required', 'date'],
            'terminationReason' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            app(ContractLifecycle::class)->terminate($this->contract, [
                'on' => $this->terminatedOn,
                'reason' => $this->terminationReason ?: null,
            ], auth()->user());
        } catch (RuntimeException $e) {
            $this->addError('terminating', $e->getMessage());

            return;
        }

        $this->terminating = false;
        $this->refresh();
        $this->dispatch('toast', message: 'Ended, and the date recorded.');
    }

    public function addObligation(): void
    {
        Gate::authorize('contracts.manage');

        $this->validate([
            'owedBy' => ['required', 'in:us,them'],
            'obligationTitle' => ['required', 'string', 'max:180'],
            'obligationDueOn' => ['nullable', 'date'],
            'obligationDescription' => ['nullable', 'string', 'max:500'],
        ], [
            'obligationTitle.required' => 'What has to be done?',
        ]);

        app(ContractLifecycle::class)->addObligation($this->contract, [
            'owed_by' => $this->owedBy,
            'title' => $this->obligationTitle,
            'due_on' => $this->obligationDueOn ?: null,
            'description' => $this->obligationDescription ?: null,
        ], auth()->user());

        $this->reset(['addingObligation', 'obligationTitle', 'obligationDueOn', 'obligationDescription']);
        $this->dispatch('toast', message: 'Added.');
    }

    public function completeObligation(string $id): void
    {
        Gate::authorize('contracts.manage');

        $obligation = ContractObligation::query()
            ->where('contract_id', $this->contract->id)
            ->findOrFail($id);

        app(ContractLifecycle::class)->completeObligation($obligation, auth()->user());

        $this->dispatch('toast', message: 'Marked as done.');
    }

    public function render(): View
    {
        Gate::authorize('contracts.view');

        $contract = $this->contract;

        return view('livewire.contracts.show', [
            // Outstanding first, and the ones with a date before the standing
            // promises: this list is read to find out what still has to happen.
            'obligations' => $contract->obligations()
                ->with('completer')
                ->orderByRaw('completed_on IS NOT NULL')
                ->orderByRaw('due_on IS NULL, due_on')
                ->get(),
            'renewals' => $contract->renewals()->with('creator')->get(),
        ])->layout('components.layouts.app', [
            'title' => $contract->title,
            'active' => 'contracts',
        ]);
    }

    /** The status and the dates both move under us, so re-read them. */
    protected function refresh(): void
    {
        $this->contract = $this->contract->fresh();
    }
}
