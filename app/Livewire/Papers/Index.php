<?php

namespace App\Livewire\Papers;

use App\Models\BusinessDocument;
use App\Models\BusinessDocumentFolder;
use App\Services\Documents\CustomDocumentTemplates;
use App\Support\DocumentKinds;
use App\Support\DocumentTemplates;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Module 13 — the document generator's front door, grown into the workspace
 * (Documents plan phase 2.1): search, filters, folder tree, overview
 * counters, on top of the template gallery that was already here.
 *
 * The gallery still leads: someone arriving here almost always wants to make
 * something, and a business that has generated three contracts in its life
 * should not be greeted by an empty table.
 */
class Index extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $state = 'all';

    #[Url]
    public string $kind = '';

    #[Url]
    public string $security = '';

    #[Url]
    public string $folderId = '';

    #[Url]
    public string $tag = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updated($property): void
    {
        if (in_array($property, ['kind', 'security', 'folderId', 'tag'], true)) {
            $this->resetPage();
        }
    }

    public function setState(string $state): void
    {
        $this->state = in_array($state, ['all', 'draft', 'issued'], true) ? $state : 'all';
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['kind', 'security', 'folderId', 'tag', 'search']);
        $this->resetPage();
    }

    public function render(): View
    {
        $user = auth()->user();

        // The class-level form of the ability, not the per-record policy
        // method: `manage` on BusinessDocumentPolicy needs a specific
        // document to check ownership against, and a list has none yet.
        $mayManage = (bool) $user?->can('papers.manage');

        $base = BusinessDocument::query()->readableBy($user, $mayManage);

        $query = (clone $base)->latest('created_at');

        if ($this->state !== 'all') {
            $query->where('status', $this->state);
        }

        if ($this->kind !== '') {
            $query->ofKind($this->kind);
        }

        if ($this->security !== '') {
            $query->where('security', $this->security);
        }

        if ($this->folderId !== '') {
            $query->where('folder_id', $this->folderId);
        }

        if ($this->tag !== '') {
            $query->whereJsonContains('tags', $this->tag);
        }

        if (($term = trim($this->search)) !== '') {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

            $query->where(fn ($q) => $q
                ->where('title', 'like', $like)
                ->orWhere('recipient', 'like', $like)
                ->orWhere('reference', 'like', $like));
        }

        return view('livewire.papers.index', [
            // The built-in catalogue first, then a business's own published
            // templates — merged rather than replaced, so a business's
            // additions never hide what shipped with the product.
            'templates' => DocumentTemplates::all() + app(CustomDocumentTemplates::class)->allPublishedAsArray(),
            'papers' => $query->paginate(12),
            'counts' => [
                'all' => (clone $base)->count(),
                'draft' => (clone $base)->where('status', 'draft')->count(),
                'issued' => (clone $base)->issued()->count(),
            ],
            'counters' => [
                'total' => (clone $base)->count(),
                'mine' => (clone $base)->where('owner_id', $user?->id)->count(),
                'drafts' => (clone $base)->where('status', 'draft')->count(),
                'expiring' => (clone $base)->expiringWithin(30)->count(),
                'archived' => (clone $base)->where('status', 'void')->count(),
            ],
            'kindGroups' => DocumentKinds::groups(),
            'folders' => BusinessDocumentFolder::query()
                ->whereNull('parent_id')
                ->with('children')
                ->orderBy('name')
                ->get(),
        ])->layout('components.layouts.app', [
            'title' => 'Documents',
            'active' => 'papers',
        ]);
    }
}
