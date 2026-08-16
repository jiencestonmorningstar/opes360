<?php

namespace App\Livewire\Papers;

use App\Models\BusinessDocument;
use App\Models\BusinessDocumentFolder;
use App\Services\Documents\BulkActions;
use App\Services\Documents\CustomDocumentTemplates;
use App\Support\DocumentKinds;
use App\Support\DocumentTemplates;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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

    /**
     * §64–68 — bulk operations. The checked documents, by id. Selection is a
     * screen state, not a URL one: a pasted link must not arrive pre-armed
     * with somebody else's selection.
     *
     * @var array<int, string>
     */
    public array $selected = [];

    public string $bulkFolderId = '';

    public string $bulkTag = '';

    public string $bulkSecurity = '';

    /**
     * The last batch's outcome, verbatim: "3 moved, 1 refused: X — reason."
     * A component property rather than a session flash so it survives exactly
     * as long as the screen showing it.
     */
    public string $bulkSummary = '';

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

    /* ----- Bulk operations (§64–68): selection ----- */

    public function selectPage(): void
    {
        $ids = $this->filteredQuery()
            ->paginate(12, ['id'], 'page', $this->getPage())
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();

        $this->selected = array_values(array_unique([...$this->selected, ...$ids]));
    }

    public function clearSelection(): void
    {
        $this->reset(['selected', 'bulkFolderId', 'bulkTag', 'bulkSecurity']);
    }

    /* ----- Bulk operations: the actions ----- */

    public function bulkMove(): void
    {
        $folder = $this->bulkFolderId !== ''
            ? BusinessDocumentFolder::query()->find($this->bulkFolderId)
            : null;

        if ($this->bulkFolderId !== '' && $folder === null) {
            $this->bulkSummary = 'That folder no longer exists.';

            return;
        }

        $this->finish(app(BulkActions::class)->move($this->selectedDocuments(), $folder, auth()->user()), 'moved');
    }

    public function bulkTagAdd(): void
    {
        $this->validate(['bulkTag' => ['required', 'string', 'max:60']]);

        $this->finish(app(BulkActions::class)->tag($this->selectedDocuments(), $this->bulkTag, auth()->user()), 'tagged');
    }

    public function bulkTagRemove(): void
    {
        $this->validate(['bulkTag' => ['required', 'string', 'max:60']]);

        $this->finish(app(BulkActions::class)->untag($this->selectedDocuments(), $this->bulkTag, auth()->user()), 'untagged');
    }

    public function bulkClassify(): void
    {
        $this->validate(['bulkSecurity' => ['required', 'string', 'in:'.implode(',', array_keys(DocumentKinds::securityLevels()))]]);

        $this->finish(app(BulkActions::class)->classify($this->selectedDocuments(), $this->bulkSecurity, auth()->user()), 'classified');
    }

    public function bulkArchive(): void
    {
        $this->finish(app(BulkActions::class)->archive($this->selectedDocuments(), auth()->user()), 'archived');
    }

    public function bulkDownload(): ?BinaryFileResponse
    {
        $documents = $this->selectedDocuments();

        if ($documents->isEmpty()) {
            $this->bulkSummary = 'Nothing selected.';

            return null;
        }

        $path = app(BulkActions::class)->zip($documents, auth()->user());

        return response()->download($path, 'documents.zip')->deleteFileAfterSend(true);
    }

    /**
     * The checked documents, refetched through the same readable scope the
     * list itself uses — an id smuggled into the selection for a document the
     * user cannot see is dropped here, before any per-item policy check even
     * runs, so a refusal report never names a document that was hidden.
     *
     * @return Collection<int, BusinessDocument>
     */
    protected function selectedDocuments()
    {
        $user = auth()->user();

        return BusinessDocument::query()
            ->readableBy($user, (bool) $user?->can('papers.manage'))
            ->whereIn('id', $this->selected)
            ->get();
    }

    /**
     * One honest sentence about the whole batch — what was done, and every
     * refusal by name — then the selection is cleared so the next batch
     * starts deliberately.
     *
     * @param  array{done: array<int, string>, refused: array<int, array{title: string, reason: string}>}  $report
     */
    protected function finish(array $report, string $pastVerb): void
    {
        if ($this->selected === []) {
            $this->bulkSummary = 'Nothing selected.';

            return;
        }

        $this->bulkSummary = app(BulkActions::class)->summarise($report, $pastVerb);

        $this->clearSelection();
    }

    /** The list query: readable scope plus every active filter. */
    protected function filteredQuery(): Builder
    {
        $user = auth()->user();

        // The class-level form of the ability, not the per-record policy
        // method: `manage` on BusinessDocumentPolicy needs a specific
        // document to check ownership against, and a list has none yet.
        $mayManage = (bool) $user?->can('papers.manage');

        $query = BusinessDocument::query()->readableBy($user, $mayManage)->latest('created_at');

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

        return $query;
    }

    public function render(): View
    {
        $user = auth()->user();

        $base = BusinessDocument::query()->readableBy($user, (bool) $user?->can('papers.manage'));

        return view('livewire.papers.index', [
            // The built-in catalogue first, then a business's own published
            // templates — merged rather than replaced, so a business's
            // additions never hide what shipped with the product.
            'templates' => DocumentTemplates::all() + app(CustomDocumentTemplates::class)->allPublishedAsArray(),
            'papers' => $this->filteredQuery()->paginate(12),
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
