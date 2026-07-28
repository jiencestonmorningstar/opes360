<?php

namespace App\Livewire\Sales;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Document;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    /** Active document type tab. */
    #[Url]
    public string $type = 'invoice';

    /** Payment-state filter: all|paid|pending|overdue|draft. */
    #[Url]
    public string $state = 'all';

    #[Url(as: 'q')]
    public string $search = '';

    public function setType(string $type): void
    {
        $this->type = DocumentType::tryFrom($type) ? $type : DocumentType::Invoice->value;
        $this->resetPage();
    }

    public function setState(string $state): void
    {
        $this->state = in_array($state, ['all', 'paid', 'pending', 'overdue', 'draft'], true)
            ? $state
            : 'all';
        $this->resetPage();
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        return view('livewire.sales.index', [
            'documents' => $this->query()->paginate(15),
            'typeCounts' => $this->typeCounts(),
        ])->layout('components.layouts.app', ['title' => 'Sales', 'active' => 'sales']);
    }

    protected function query(): Builder
    {
        return Document::query()
            ->ofType($this->type)
            ->with('contact')
            ->when($this->search !== '', function (Builder $query) {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($this->search)).'%';

                $query->where(fn (Builder $q) => $q
                    ->where('number', 'like', $term)
                    ->orWhereHas('contact', fn (Builder $c) => $c
                        ->where('name', 'like', $term)
                        ->orWhere('company_name', 'like', $term)));
            })
            ->when($this->state !== 'all', fn (Builder $query) => $this->applyState($query))
            // Post-dated documents sink below current ones: the list answers
            // "what needs attention now", not "what is chronologically last".
            ->orderByRaw('CASE WHEN issue_date > ? THEN 1 ELSE 0 END', [now()->endOfDay()])
            ->latest('issue_date')
            ->latest('created_at')
            ->orderByDesc('number');
    }

    /**
     * Filters mirror paymentState() on the model: users think in "is it paid?",
     * not in lifecycle statuses, so the chips speak the same language as the
     * badges on the rows.
     */
    protected function applyState(Builder $query): Builder
    {
        return match ($this->state) {
            'draft' => $query->where('status', DocumentStatus::Draft->value),
            'paid' => $query
                ->whereNotIn('status', [DocumentStatus::Draft->value, DocumentStatus::Void->value])
                ->where('balance', '<=', 0),
            'overdue' => $query->outstanding()->whereDate('due_date', '<', now()->toDateString()),
            'pending' => $query->outstanding()->where(fn (Builder $q) => $q
                ->whereNull('due_date')
                ->orWhereDate('due_date', '>=', now()->toDateString())),
            default => $query,
        };
    }

    /**
     * Badge count per tab, one grouped query rather than seven.
     *
     * The alias must not collide with a cast attribute: `total` would pick up
     * the model's decimal:2 cast and render a count of 55 as "55.00".
     */
    protected function typeCounts(): array
    {
        return Document::query()
            ->selectRaw('type, COUNT(*) as tab_count')
            ->groupBy('type')
            ->pluck('tab_count', 'type')
            ->all();
    }
}
