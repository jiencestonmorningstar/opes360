<?php

namespace App\Livewire\Insurance;

use App\Models\Contact;
use App\Models\InsuranceClaim;
use App\Models\InsurancePolicy;
use App\Services\Insurance\Policies;
use App\Support\CurrentCompany;
use App\Support\PolicyWatch;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use RuntimeException;

/**
 * What is about to lapse, and then the book.
 *
 * The order on this screen is the argument the vertical is making. A policy
 * register opened on the list of everything is a filing cabinet; the dates on
 * which cover runs out are the only part that costs a client — and the
 * brokerage's E&O cover — by being ignored. So the watch comes first and the
 * alphabet comes second, exactly as the contracts screen reasons.
 */
class Index extends Component
{
    use WithPagination;

    #[Url]
    public string $tab = 'policies';

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $status = '';

    // ── Placing one ─────────────────────────────────────────────────────
    public bool $placing = false;

    public ?string $holderId = null;

    public ?string $insurerId = null;

    public string $policyNumber = '';

    public string $productLine = 'motor';

    public string $premium = '';

    public string $commissionPercent = '';

    public string $coversFrom = '';

    public string $coversTo = '';

    public string $renewalType = 'manual';

    public string $renewalTermMonths = '';

    public string $noticePeriodDays = '';

    public function mount(): void
    {
        Gate::authorize('insurance.view');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function startPlacing(): void
    {
        Gate::authorize('insurance.manage');

        $this->reset(['holderId', 'insurerId', 'policyNumber', 'premium', 'commissionPercent', 'coversTo', 'renewalTermMonths', 'noticePeriodDays']);
        $this->resetValidation();
        $this->productLine = 'motor';
        $this->renewalType = 'manual';
        $this->coversFrom = now()->toDateString();
        $this->placing = true;
    }

    public function place(): void
    {
        Gate::authorize('insurance.manage');

        $this->validate([
            'holderId' => ['required', 'string'],
            'insurerId' => ['nullable', 'string'],
            'policyNumber' => ['nullable', 'string', 'max:120'],
            'productLine' => ['required', 'in:'.implode(',', array_keys(InsurancePolicy::PRODUCT_LINES))],
            'premium' => ['nullable', 'numeric', 'min:0'],
            'commissionPercent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'coversFrom' => ['required', 'date'],
            'coversTo' => ['nullable', 'date'],
            'renewalType' => ['required', 'in:'.implode(',', array_keys(InsurancePolicy::RENEWAL_TYPES))],
            'renewalTermMonths' => ['nullable', 'integer', 'min:1', 'max:120'],
            'noticePeriodDays' => ['nullable', 'integer', 'min:1', 'max:365'],
        ], [
            'holderId.required' => 'Who is being covered?',
        ]);

        try {
            app(Policies::class)->place([
                'holder_contact_id' => $this->holderId,
                'insurer_contact_id' => $this->insurerId ?: null,
                'policy_number' => $this->policyNumber ?: null,
                'product_line' => $this->productLine,
                'premium' => $this->premium === '' ? null : (float) $this->premium,
                'commission_percent' => $this->commissionPercent === '' ? null : (float) $this->commissionPercent,
                'covers_from' => $this->coversFrom,
                'covers_to' => $this->coversTo ?: null,
                'renewal_type' => $this->renewalType,
                'renewal_term_months' => $this->renewalTermMonths === '' ? null : (int) $this->renewalTermMonths,
                'notice_period_days' => $this->noticePeriodDays === '' ? null : (int) $this->noticePeriodDays,
            ], auth()->user());
        } catch (RuntimeException $e) {
            // The service refuses in words somebody holding the schedule can
            // act on; shown against the form rather than as "something went
            // wrong".
            $this->addError('placing', $e->getMessage());

            return;
        }

        $this->placing = false;
        $this->resetPage();
        $this->dispatch('toast', message: 'Policy placed. Bind it once the insurer is on risk.');
    }

    public function bind(string $id): void
    {
        Gate::authorize('insurance.manage');

        try {
            app(Policies::class)->bind(InsurancePolicy::findOrFail($id), auth()->user());
        } catch (RuntimeException $e) {
            $this->addError('register', $e->getMessage());

            return;
        }

        $this->dispatch('toast', message: 'Bound — the cover is in force and on the watch.');
    }

    public function render(): View
    {
        Gate::authorize('insurance.view');

        $watch = new PolicyWatch;

        return view('livewire.insurance.index', [
            'summary' => $watch->summary(),
            'lapsing' => $watch->coverLapsing(),
            // Split once rather than asking the watch twice: the alarm list
            // and the merely-lapsed list are one query read two ways, and a
            // second round trip would let them disagree at midnight.
            'lapsed' => ($allLapsed = $watch->lapsed())->reject->autoRenews()->values(),
            'lapsedOnAutoRenew' => $allLapsed->filter->autoRenews()->values(),
            'policies' => InsurancePolicy::query()
                ->with(['holder', 'insurer'])
                ->when($this->search !== '', function ($query) {
                    $term = '%'.$this->search.'%';
                    $query->where(fn ($q) => $q->where('policy_number', 'like', $term)
                        ->orWhere('product_line', 'like', $term)
                        ->orWhereHas('holder', fn ($h) => $h->where('name', 'like', $term)
                            ->orWhere('company_name', 'like', $term)));
                })
                ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
                ->orderByRaw('covers_to IS NULL, covers_to')
                ->paginate(20),
            'claims' => $this->tab === 'claims'
                ? InsuranceClaim::query()->with('policy.holder')->orderByDesc('incident_on')->get()->groupBy('status')
                : collect(),
            'contacts' => Contact::query()->orderBy('company_name')->orderBy('name')->get(),
            'currency' => app(CurrentCompany::class)->get()?->currency ?? 'XAF',
        ])->layout('components.layouts.app', ['title' => 'Insurance', 'active' => 'insurance']);
    }
}
