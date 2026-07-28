<?php

namespace App\Livewire\Customers;

use App\Models\Contact;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    /** Contact type tab: customer|supplier|vendor|lead. */
    #[Url]
    public string $type = 'customer';

    #[Url(as: 'q')]
    public string $search = '';

    public const TYPES = [
        'customer' => 'Customers',
        'supplier' => 'Suppliers',
        'vendor' => 'Vendors',
        'lead' => 'Leads',
    ];

    public function setType(string $type): void
    {
        $this->type = array_key_exists($type, self::TYPES) ? $type : 'customer';
        $this->resetPage();
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        return view('livewire.customers.index', [
            'contacts' => $this->query()->paginate(20),
            // Alias must not collide with a cast attribute (see Sales\Index).
            'typeCounts' => Contact::query()
                ->selectRaw('type, COUNT(*) as tab_count')
                ->groupBy('type')
                ->pluck('tab_count', 'type')
                ->all(),
        ])->layout('components.layouts.app', ['title' => 'Customers', 'active' => 'customers']);
    }

    protected function query(): Builder
    {
        return Contact::query()
            ->where('type', $this->type)
            ->when($this->search !== '', function (Builder $query) {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($this->search)).'%';

                $query->where(fn (Builder $q) => $q
                    ->where('name', 'like', $term)
                    ->orWhere('company_name', 'like', $term)
                    ->orWhere('email', 'like', $term));
            })
            // Money owed floats to the top; ties resolve alphabetically.
            ->orderByDesc('balance')
            ->orderBy('name');
    }
}
