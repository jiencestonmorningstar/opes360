<?php

namespace App\Livewire\Contracts;

use App\Models\Contact;
use App\Models\Contract;
use App\Services\Contracts\ContractLifecycle;
use App\Support\ContractWatch;
use App\Support\CurrentCompany;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use RuntimeException;

/**
 * What is about to go wrong, and then the register.
 *
 * The order on this screen is the argument the module is making. A contract
 * register opened on the list of everything is a filing cabinet, and a filing
 * cabinet is what let the cleaning contract roll over for another year. So the
 * watch comes first and the alphabet comes second.
 *
 * The three lists stay apart for the reason ContractWatch keeps them apart: a
 * list that mixes "act before Friday" with "it is already too late" gets
 * skimmed, and then neither gets done.
 */
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $status = '';

    // ── Raising one ─────────────────────────────────────────────────────
    public bool $raising = false;

    public string $title = '';

    public ?string $contactId = null;

    public string $direction = 'inbound';

    public string $type = 'service';

    public string $value = '';

    public string $startsOn = '';

    public string $endsOn = '';

    public string $renewalType = 'none';

    public string $renewalTermMonths = '';

    public string $noticePeriodDays = '';

    public string $description = '';

    public function mount(): void
    {
        Gate::authorize('contracts.view');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function startRaising(): void
    {
        Gate::authorize('contracts.manage');

        $this->reset(['title', 'contactId', 'value', 'endsOn', 'renewalTermMonths', 'noticePeriodDays', 'description']);
        $this->resetValidation();
        $this->direction = 'inbound';
        $this->type = 'service';
        $this->renewalType = 'none';
        $this->startsOn = now()->toDateString();
        $this->raising = true;
    }

    public function raise(): void
    {
        Gate::authorize('contracts.manage');

        $this->validate([
            'title' => ['required', 'string', 'max:180'],
            'contactId' => ['nullable', 'string'],
            'direction' => ['required', 'in:inbound,outbound'],
            'type' => ['required', 'in:'.implode(',', array_keys(Contract::TYPES))],
            'value' => ['nullable', 'numeric', 'min:0'],
            'startsOn' => ['required', 'date'],
            'endsOn' => ['nullable', 'date'],
            'renewalType' => ['required', 'in:'.implode(',', array_keys(Contract::RENEWAL_TYPES))],
            'renewalTermMonths' => ['nullable', 'integer', 'min:1', 'max:120'],
            'noticePeriodDays' => ['nullable', 'integer', 'min:1', 'max:730'],
        ], [
            'title.required' => 'What is the agreement called?',
        ]);

        try {
            app(ContractLifecycle::class)->raise([
                'title' => $this->title,
                'contact_id' => $this->contactId ?: null,
                'direction' => $this->direction,
                'type' => $this->type,
                'value' => $this->value === '' ? null : (float) $this->value,
                'starts_on' => $this->startsOn,
                'ends_on' => $this->endsOn ?: null,
                'renewal_type' => $this->renewalType,
                'renewal_term_months' => $this->renewalTermMonths === '' ? null : (int) $this->renewalTermMonths,
                'notice_period_days' => $this->noticePeriodDays === '' ? null : (int) $this->noticePeriodDays,
                'description' => $this->description ?: null,
            ], auth()->user());
        } catch (RuntimeException $e) {
            /*
             * The service refuses an auto-renewing contract with no notice
             * period, and says why in words somebody holding the paper can act
             * on. Shown against the form so they can read the number off it
             * and try again, rather than being told "something went wrong".
             */
            $this->addError('raising', $e->getMessage());

            return;
        }

        $this->raising = false;
        $this->resetPage();
        $this->dispatch('toast', message: 'Contract raised. Submit it for approval when the terms are settled.');
    }

    public function submit(string $id): void
    {
        Gate::authorize('contracts.manage');

        try {
            app(ContractLifecycle::class)->submit(Contract::findOrFail($id), auth()->user());
        } catch (RuntimeException $e) {
            $this->addError('register', $e->getMessage());

            return;
        }

        $this->dispatch('toast', message: 'Sent for approval. It will show up in the approver\'s actions.');
    }

    public function render(): View
    {
        Gate::authorize('contracts.view');

        $watch = new ContractWatch;

        $missed = $watch->noticeMissed();

        return view('livewire.contracts.index', [
            'summary' => $watch->summary(),
            'lapsing' => $watch->noticeLapsing(),
            // Split here rather than by asking the watch twice: the alarm list
            // and the merely-late list are the same query read two ways, and a
            // second round trip would let them disagree at midnight.
            'renewingAtRisk' => $missed->filter->autoRenews()->values(),
            'missed' => $missed->reject->autoRenews()->values(),
            'contracts' => Contract::query()
                ->with(['counterparty', 'owner'])
                ->when($this->search !== '', function ($query) {
                    $term = '%'.$this->search.'%';
                    $query->where(fn ($q) => $q->where('title', 'like', $term)
                        ->orWhere('description', 'like', $term));
                })
                ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
                ->orderByRaw('ends_on IS NULL, ends_on')
                ->paginate(20),
            'counterparties' => Contact::query()->orderBy('company_name')->orderBy('name')->get(),
            'currency' => app(CurrentCompany::class)->get()?->currency ?? 'XAF',
        ])->layout('components.layouts.app', ['title' => 'Contracts', 'active' => 'contracts']);
    }
}
