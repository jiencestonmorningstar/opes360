<?php

namespace App\Livewire\Partners;

use App\Models\PartnerClient;
use App\Support\CurrentCompany;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * A secretariat's client book: the businesses it prints for.
 *
 * These are not Contacts. A contact is somebody the company sells to, with a
 * balance and a document history; a partner client is a business the partner
 * produces stationery for and hopes to enrol, which needs an invite link and a
 * conversion state instead.
 */
class Clients extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $filter = 'all'; // all|converted|prospect

    public bool $adding = false;

    public string $name = '';

    public string $contactName = '';

    public string $phone = '';

    public string $email = '';

    public string $industry = '';

    public string $city = '';

    public function mount(): void
    {
        Gate::authorize('partners.view');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedFilter(): void
    {
        $this->resetPage();
    }

    public function startAdding(): void
    {
        Gate::authorize('partners.manage');

        $this->reset(['name', 'contactName', 'phone', 'email', 'industry', 'city']);
        $this->resetValidation();
        $this->adding = true;
    }

    public function cancel(): void
    {
        $this->adding = false;
        $this->resetValidation();
    }

    public function save(): void
    {
        Gate::authorize('partners.manage');

        $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'contactName' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:160'],
            'industry' => ['nullable', 'string', 'max:60'],
            'city' => ['nullable', 'string', 'max:80'],
        ], [
            'name.required' => 'The client needs a business name.',
        ]);

        PartnerClient::create([
            'name' => trim($this->name),
            'contact_name' => $this->contactName ?: null,
            'phone' => $this->phone ?: null,
            'email' => $this->email ? mb_strtolower(trim($this->email)) : null,
            'industry' => $this->industry ?: null,
            'city' => $this->city ?: null,
        ]);

        $this->adding = false;
        $this->reset(['name', 'contactName', 'phone', 'email', 'industry', 'city']);
        $this->resetPage();

        session()->flash('status', 'Client added.');
    }

    public function render(): View
    {
        $company = app(CurrentCompany::class)->get();

        $clients = PartnerClient::query()
            ->when($this->search !== '', function ($query) {
                $term = '%'.$this->search.'%';
                $query->where(fn ($q) => $q->where('name', 'like', $term)
                    ->orWhere('contact_name', 'like', $term)
                    ->orWhere('phone', 'like', $term));
            })
            ->when($this->filter === 'converted', fn ($q) => $q->whereNotNull('converted_company_id'))
            ->when($this->filter === 'prospect', fn ($q) => $q->whereNull('converted_company_id'))
            ->withCount(['issuances' => fn ($q) => $q->where('status', 'billed')])
            ->latest()
            ->paginate(15);

        return view('livewire.partners.clients', [
            'clients' => $clients,
            'partnerCode' => $company?->partnerCode(),
            'cardFee' => (int) config('opes.partners.card_fee'),
        ])->layout('components.layouts.app', [
            'title' => 'Clients',
            'active' => 'partners',
        ]);
    }
}
