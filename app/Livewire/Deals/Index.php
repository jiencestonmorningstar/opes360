<?php

namespace App\Livewire\Deals;

use App\Models\Deal;
use App\Services\DealPipeline;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Url;
use Livewire\Component;
use RuntimeException;

/**
 * The pipeline board.
 *
 * Moving a card calls DealPipeline — the same service the API calls — rather
 * than writing the stage straight to the model. Two places deciding what
 * "won" means is how `closed_at` ends up set on half the won deals.
 */
class Index extends Component
{
    use AuthorizesRequests;

    #[Url(as: 'q')]
    public string $search = '';

    /** Closed deals are hidden by default: a board is about work in progress. */
    #[Url]
    public bool $showClosed = false;

    public function move(string $id, string $stage): void
    {
        $deal = Deal::findOrFail($id);

        $this->authorize('update', $deal);

        app(DealPipeline::class)->moveTo($deal, $stage);
    }

    /**
     * Start the invoice for a won deal.
     *
     * Asks for the sales permission as well as the deal one: this writes into
     * the sales module, and whoever chases deals is often deliberately not the
     * person who bills for them.
     */
    public function invoice(string $id): void
    {
        $deal = Deal::findOrFail($id);

        $this->authorize('update', $deal);
        $this->authorize('sales.create');

        try {
            $document = app(DealPipeline::class)->convertToInvoice($deal, auth()->user());
        } catch (RuntimeException $e) {
            session()->flash('error', $e->getMessage());

            return;
        }

        // Straight to the draft rather than a toast: the invoice still needs
        // its lines checked, and this is the moment somebody is willing to.
        $this->redirectRoute('documents.show', $document, navigate: true);
    }

    /**
     * How many cards a column shows. The board used to load every matching
     * deal on every render, which is fine at fifty deals and a page-long
     * stall at five thousand. Fifty covers what anybody works from a board;
     * past that the column header carries the real count, and search — which
     * narrows the query itself, not the loaded page — is how the rest are
     * reached.
     */
    public const COLUMN_LIMIT = 50;

    public function render(): View
    {
        $this->authorize('viewAny', Deal::class);

        $stages = $this->stages();

        /*
         * Counts and value totals come from one grouped aggregate rather than
         * the loaded cards: with columns capped, summing what was loaded
         * would silently under-report the pipeline's worth.
         */
        $summary = $this->query(withRelations: false)
            ->reorder()
            ->toBase()
            ->selectRaw('stage, COUNT(*) as n, SUM(value) as value')
            ->groupBy('stage')
            ->get()
            ->keyBy('stage');

        $columns = [];

        foreach ($stages as $stage) {
            // One capped query per stage — a handful of indexed LIMIT queries,
            // each bounded no matter how big the pipeline grows.
            $columns[$stage] = $this->query()
                ->where('stage', $stage)
                ->limit(self::COLUMN_LIMIT)
                ->get();
        }

        $open = collect($summary)->except(Deal::CLOSED_STAGES);

        return view('livewire.deals.index', [
            'columns' => $columns,
            'counts' => collect($summary)->map(fn ($row) => (int) $row->n),
            'totals' => collect($summary)->map(fn ($row) => (float) $row->value),
            'openCount' => (int) $open->sum('n'),
            'openValue' => (float) $open->sum('value'),
        ])->layout('components.layouts.app', ['title' => 'Pipeline', 'active' => 'deals']);
    }

    /** @return array<int, string> */
    protected function stages(): array
    {
        return $this->showClosed
            ? array_keys(Deal::STAGES)
            : array_values(array_diff(array_keys(Deal::STAGES), Deal::CLOSED_STAGES));
    }

    protected function query(bool $withRelations = true): Builder
    {
        return Deal::query()
            ->when($withRelations, fn (Builder $q) => $q->with('contact'))
            ->when(! $this->showClosed, fn (Builder $q) => $q->open())
            ->when($this->search !== '', function (Builder $query) {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($this->search)).'%';

                $query->where(function (Builder $q) use ($term) {
                    $q->where('title', 'like', $term)
                        ->orWhere('lead_name', 'like', $term)
                        ->orWhereHas('contact', fn (Builder $c) => $c->where('name', 'like', $term));
                });
            })
            ->latest();
    }
}
