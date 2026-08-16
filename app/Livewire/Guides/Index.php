<?php

namespace App\Livewire\Guides;

use App\Support\Guides;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The in-product documentation.
 *
 * Ungated, deliberately. Documentation that is only readable by people who
 * already have the permission being documented is documentation nobody can use
 * to find out what a permission does — and none of it is confidential: it
 * describes how the product works, not what any business has in it.
 */
class Index extends Component
{
    #[Url(as: 'guide', keep: false)]
    public string $slug = 'getting-started';

    #[Url(as: 'q', keep: false)]
    public string $search = '';

    public function mount(?string $slug = null): void
    {
        if ($slug !== null && Guides::exists($slug)) {
            $this->slug = $slug;
        }
    }

    public function open(string $slug): void
    {
        if (Guides::exists($slug)) {
            $this->slug = $slug;
        }
    }

    public function updatedSearch(): void
    {
        /*
         * Jump to the first match as you type. Searching and then still having
         * to click the result is two actions for one intention, and the list
         * stays on screen so a different match is one click away.
         */
        $matches = Guides::search($this->search);

        if ($this->search !== '' && $matches !== []) {
            $this->slug = $matches[0]['slug'];
        }
    }

    public function render(): View
    {
        $matches = Guides::search($this->search);
        $visible = array_column($matches, 'slug');

        // A guide opened before searching stays open even if it does not match,
        // so typing does not yank the page out from under the reader.
        $current = Guides::find($this->slug) ?? Guides::find('getting-started');

        return view('livewire.guides.index', [
            'grouped' => $this->groupedMatches($visible),
            'current' => $current,
            'html' => Guides::html($current['slug']),
            'matchCount' => count($matches),
        ])->layout('components.layouts.app', ['title' => 'Guides', 'active' => 'guides']);
    }

    /**
     * @param  array<int, string>  $visible
     * @return array<string, array<int, array<string, mixed>>>
     */
    protected function groupedMatches(array $visible): array
    {
        $grouped = [];

        foreach (Guides::grouped() as $group => $guides) {
            $kept = array_values(array_filter(
                $guides,
                fn (array $guide) => in_array($guide['slug'], $visible, true),
            ));

            if ($kept !== []) {
                $grouped[$group] = $kept;
            }
        }

        return $grouped;
    }
}
