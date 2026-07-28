<?php

namespace App\Livewire\Papers;

use App\Models\BusinessDocument;
use App\Support\DocumentTemplates;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Module 13 — the document generator's front door.
 *
 * The template gallery leads, not the list of past documents: someone arriving
 * here almost always wants to make something, and a business that has generated
 * three contracts in its life should not be greeted by an empty table.
 */
class Index extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $state = 'all';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function setState(string $state): void
    {
        $this->state = in_array($state, ['all', 'draft', 'issued'], true) ? $state : 'all';
        $this->resetPage();
    }

    public function render(): View
    {
        $query = BusinessDocument::query()->latest('created_at');

        if ($this->state !== 'all') {
            $query->where('status', $this->state);
        }

        if (($term = trim($this->search)) !== '') {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

            $query->where(fn ($q) => $q
                ->where('title', 'like', $like)
                ->orWhere('recipient', 'like', $like)
                ->orWhere('reference', 'like', $like));
        }

        return view('livewire.papers.index', [
            'templates' => DocumentTemplates::all(),
            'papers' => $query->paginate(12),
            'counts' => [
                'all' => BusinessDocument::count(),
                'draft' => BusinessDocument::where('status', 'draft')->count(),
                'issued' => BusinessDocument::issued()->count(),
            ],
        ])->layout('components.layouts.app', [
            'title' => 'Documents',
            'active' => 'papers',
        ]);
    }
}
