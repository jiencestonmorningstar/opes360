<?php

namespace App\Livewire\Deals;

use App\Models\Deal;
use App\Services\DealPipeline;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
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

    public function render(): View
    {
        $this->authorize('viewAny', Deal::class);

        $deals = $this->query()->get();

        return view('livewire.deals.index', [
            'columns' => $this->columns($deals),
            'totals' => $deals->groupBy('stage')->map(fn (Collection $g) => $g->sum('value')),
            'openCount' => $deals->filter->isOpen()->count(),
            'openValue' => $deals->filter->isOpen()->sum('value'),
        ])->layout('components.layouts.app', ['title' => 'Pipeline', 'active' => 'deals']);
    }

    /** @return array<string, Collection<int, Deal>> */
    protected function columns(Collection $deals): array
    {
        $stages = $this->showClosed
            ? array_keys(Deal::STAGES)
            : array_values(array_diff(array_keys(Deal::STAGES), Deal::CLOSED_STAGES));

        $grouped = $deals->groupBy('stage');

        $columns = [];

        foreach ($stages as $stage) {
            $columns[$stage] = $grouped->get($stage, collect());
        }

        return $columns;
    }

    protected function query(): Builder
    {
        return Deal::query()
            ->with('contact')
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
