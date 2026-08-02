<?php

namespace App\Livewire\Assets;

use App\Models\Contact;
use App\Models\DepreciationEntry;
use App\Models\FixedAsset;
use App\Services\Assets\AssetRegister;
use App\Support\Accounting\ChartOfAccounts;
use App\Support\CurrentCompany;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use RuntimeException;

/**
 * What the business owns.
 *
 * Two figures carry the screen: what it all cost, and what it is still worth.
 * The gap between them is depreciation, which is a cost most small businesses
 * have never seen written down anywhere.
 */
class Index extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $filter = 'active'; // active|disposed|all

    public bool $adding = false;

    public ?string $disposing = null;

    // ── New asset ───────────────────────────────────────────────────────
    public string $name = '';

    public string $category = 'equipment';

    public string $reference = '';

    public ?string $supplierId = null;

    public string $acquiredOn = '';

    public string $cost = '';

    public string $residualValue = '0';

    public string $method = 'straight_line';

    public string $usefulLifeMonths = '60';

    public string $openingAccumulated = '0';

    public string $fundedBy = 'bank';

    public string $location = '';

    // ── Depreciation run ────────────────────────────────────────────────
    public string $period = '';

    // ── Disposal ────────────────────────────────────────────────────────
    public string $disposedOn = '';

    public string $proceeds = '0';

    public string $receivedBy = 'bank';

    public string $disposalNote = '';

    public function mount(): void
    {
        Gate::authorize('assets.view');

        $this->acquiredOn = now()->toDateString();
        $this->period = now()->startOfMonth()->toDateString();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedFilter(): void
    {
        $this->resetPage();
    }

    /** Picking a category suggests its usual life; the field stays editable. */
    public function updatedCategory(): void
    {
        $this->usefulLifeMonths = (string) ChartOfAccounts::assetDefaultLife($this->category);
    }

    public function startAdding(): void
    {
        Gate::authorize('assets.create');

        $this->reset(['name', 'reference', 'cost', 'location', 'supplierId']);
        $this->resetValidation();
        $this->acquiredOn = now()->toDateString();
        $this->category = 'equipment';
        $this->usefulLifeMonths = (string) ChartOfAccounts::assetDefaultLife('equipment');
        $this->residualValue = '0';
        $this->openingAccumulated = '0';
        $this->method = 'straight_line';
        $this->fundedBy = 'bank';
        $this->adding = true;
    }

    public function cancel(): void
    {
        $this->adding = false;
        $this->disposing = null;
        $this->resetValidation();
    }

    public function save(): void
    {
        Gate::authorize('assets.create');

        $this->validate([
            'name' => ['required', 'string', 'max:160'],
            'category' => ['required', 'in:'.implode(',', array_keys(ChartOfAccounts::ASSET_CATEGORIES))],
            'reference' => ['nullable', 'string', 'max:60'],
            'supplierId' => ['nullable', 'string'],
            'acquiredOn' => ['required', 'date'],
            'cost' => ['required', 'numeric', 'min:0.01'],
            'residualValue' => ['required', 'numeric', 'min:0', 'lt:cost'],
            'method' => ['required', 'in:'.implode(',', array_keys(FixedAsset::METHODS))],
            'usefulLifeMonths' => ['required', 'integer', 'min:0', 'max:1200'],
            'openingAccumulated' => ['required', 'numeric', 'min:0'],
            'fundedBy' => ['required', 'in:cash,mobile_money,bank,cheque,credit'],
            'location' => ['nullable', 'string', 'max:120'],
        ], [
            'name.required' => 'What is it?',
            'cost.min' => 'An asset has to have cost something.',
            'residualValue.lt' => 'What it will be worth at the end has to be less than what it cost.',
        ]);

        app(AssetRegister::class)->record([
            'name' => $this->name,
            'category' => $this->category,
            'reference' => $this->reference ?: null,
            'supplier_id' => $this->supplierId ?: null,
            'acquired_on' => $this->acquiredOn,
            'cost' => (float) $this->cost,
            'residual_value' => (float) $this->residualValue,
            'method' => $this->method,
            'useful_life_months' => (int) $this->usefulLifeMonths,
            'opening_accumulated' => (float) $this->openingAccumulated,
            'funded_by' => $this->fundedBy === 'credit' ? null : $this->fundedBy,
            'location' => $this->location ?: null,
            /*
             * An asset carried over from a previous system already sits in
             * somebody's books; posting it here would capitalise it twice. The
             * opening accumulated depreciation is how the business says so.
             */
            'post' => (float) $this->openingAccumulated <= 0,
        ], auth()->user());

        $this->adding = false;
        $this->resetPage();

        session()->flash('status', 'Asset recorded.');
    }

    /** Charge a month across the whole register. */
    public function runDepreciation(): void
    {
        Gate::authorize('assets.depreciate');

        $this->validate(['period' => ['required', 'date']]);

        $result = app(AssetRegister::class)->depreciateAll($this->period, auth()->user());

        session()->flash('status', $result['charged'] === 0
            ? 'Nothing to charge for that month — everything is either fully written off or not yet in use.'
            : "Depreciation charged on {$result['charged']} ".str('asset')->plural($result['charged']).'.');
    }

    public function startDisposing(string $id): void
    {
        Gate::authorize('assets.dispose');

        $asset = FixedAsset::query()->find($id);

        if ($asset === null || $asset->isDisposed()) {
            return;
        }

        $this->disposing = $id;
        $this->disposedOn = now()->toDateString();
        $this->proceeds = '0';
        $this->receivedBy = 'bank';
        $this->disposalNote = '';
        $this->resetValidation();
    }

    public function dispose(): void
    {
        Gate::authorize('assets.dispose');

        $this->validate([
            'disposedOn' => ['required', 'date'],
            'proceeds' => ['required', 'numeric', 'min:0'],
            'receivedBy' => ['required', 'in:cash,mobile_money,bank,cheque'],
            'disposalNote' => ['nullable', 'string', 'max:180'],
        ]);

        $asset = FixedAsset::query()->findOrFail($this->disposing);

        try {
            app(AssetRegister::class)->dispose($asset, [
                'disposed_on' => $this->disposedOn,
                'proceeds' => (float) $this->proceeds,
                'received_by' => $this->receivedBy,
                'note' => $this->disposalNote ?: null,
            ], auth()->user());

            $this->disposing = null;
            session()->flash('status', 'Disposed of, and the books updated.');
        } catch (RuntimeException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function render(): View
    {
        $base = fn () => FixedAsset::query()
            ->when($this->filter === 'active', fn ($q) => $q->where('status', 'active'))
            ->when($this->filter === 'disposed', fn ($q) => $q->whereIn('status', ['disposed', 'written_off']));

        $assets = $base()
            ->when($this->search !== '', function ($query) {
                $term = '%'.$this->search.'%';
                $query->where(fn ($q) => $q->where('name', 'like', $term)
                    ->orWhere('reference', 'like', $term)
                    ->orWhere('location', 'like', $term));
            })
            ->orderByDesc('acquired_on')
            ->paginate(20);

        // From SQL rather than the page on screen, or "what we own" would only
        // ever count twenty rows.
        $onlyActive = fn () => FixedAsset::query()->where('status', 'active');

        return view('livewire.assets.index', [
            'assets' => $assets,
            'totalCost' => (float) $onlyActive()->sum('cost'),
            'totalBookValue' => (float) $onlyActive()
                ->selectRaw('COALESCE(SUM(cost - accumulated_depreciation), 0) AS net')
                ->value('net'),
            'chargedThisYear' => (float) DepreciationEntry::query()
                ->whereDate('period', '>=', now()->startOfYear()->toDateString())
                ->sum('amount'),
            'categories' => ChartOfAccounts::assetCategoryOptions(),
            'suppliers' => Contact::query()
                ->whereIn('type', ['supplier', 'vendor'])
                ->orderBy('company_name')
                ->get(['id', 'first_name', 'last_name', 'company_name']),
            'currency' => app(CurrentCompany::class)->get()?->currency ?? 'XAF',
        ])->layout('components.layouts.app', ['title' => 'Assets', 'active' => 'assets']);
    }
}
