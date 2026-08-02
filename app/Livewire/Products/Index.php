<?php

namespace App\Livewire\Products;

use App\Models\Item;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    #[Url]
    public string $type = 'product';

    #[Url(as: 'q')]
    public string $search = '';

    public function setType(string $type): void
    {
        $this->type = in_array($type, ['product', 'service'], true) ? $type : 'product';
        $this->resetPage();
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        return view('livewire.products.index', [
            'items' => $this->query()->paginate(20),
            'typeCounts' => Item::query()
                ->selectRaw('type, COUNT(*) as tab_count')
                ->groupBy('type')
                ->pluck('tab_count', 'type')
                ->all(),
        ])->layout('components.layouts.app', ['title' => 'Products', 'active' => 'products']);
    }

    protected function query(): Builder
    {
        return Item::query()
            // One aggregate for the page rather than a SUM per row: the list
            // shows "n in stock" against every product.
            ->withStock()
            ->where('type', $this->type)
            ->when($this->search !== '', function (Builder $query) {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($this->search)).'%';

                $query->where(fn (Builder $q) => $q
                    ->where('name', 'like', $term)
                    ->orWhere('sku', 'like', $term)
                    ->orWhere('barcode', 'like', $term));
            })
            ->orderBy('name');
    }
}
