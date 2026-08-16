<?php

namespace App\Livewire\Estate;

use App\Models\Contact;
use App\Models\Property;
use App\Support\CurrentCompany;
use App\Support\OccupancyBoard;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The board first, the buildings second.
 *
 * The order is the argument, the same one the contracts screen makes: a
 * property list opened on the list is a filing cabinet. What costs a letting
 * business money is the vacant door, the tenant three months behind and the
 * lease quietly running out — so those come first and the addresses come
 * after.
 */
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    // ── Adding a property ───────────────────────────────────────────────
    public bool $adding = false;

    public string $name = '';

    public string $address = '';

    public string $kind = 'residential';

    public ?string $landlordId = null;

    public function mount(): void
    {
        Gate::authorize('estate.view');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function startAdding(): void
    {
        Gate::authorize('estate.manage');

        $this->reset(['name', 'address', 'landlordId']);
        $this->resetValidation();
        $this->kind = 'residential';
        $this->adding = true;
    }

    public function add(): void
    {
        Gate::authorize('estate.manage');

        $this->validate([
            'name' => ['required', 'string', 'max:180'],
            'address' => ['nullable', 'string', 'max:500'],
            'kind' => ['required', 'in:'.implode(',', array_keys(Property::KINDS))],
            'landlordId' => ['nullable', 'string'],
        ], [
            'name.required' => 'What is the property called?',
        ]);

        $property = Property::create([
            'company_id' => app(CurrentCompany::class)->get()->id,
            'name' => $this->name,
            'address' => $this->address ?: null,
            'kind' => $this->kind,
            'landlord_contact_id' => $this->landlordId ?: null,
            'created_by' => auth()->id(),
        ]);

        $this->adding = false;
        $this->dispatch('toast', message: 'Property added. Add its units, then move tenants in.');
        $this->redirectRoute('estate.show', $property, navigate: true);
    }

    public function render(): View
    {
        Gate::authorize('estate.view');

        $board = new OccupancyBoard;

        return view('livewire.estate.index', [
            'summary' => $board->summary(),
            'vacancies' => $board->vacancies(),
            'arrears' => $board->arrears(),
            'endings' => $board->leasesEnding(),
            'properties' => Property::query()
                ->with(['landlord', 'units'])
                ->when($this->search !== '', function ($query) {
                    $term = '%'.$this->search.'%';
                    $query->where(fn ($q) => $q->where('name', 'like', $term)
                        ->orWhere('address', 'like', $term));
                })
                ->orderBy('name')
                ->paginate(20),
            'landlords' => Contact::query()->orderBy('company_name')->orderBy('name')->get(),
            'currency' => app(CurrentCompany::class)->get()?->currency ?? 'XAF',
        ])->layout('components.layouts.app', ['title' => 'Properties', 'active' => 'estate']);
    }
}
