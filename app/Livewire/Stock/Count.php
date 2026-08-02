<?php

namespace App\Livewire\Stock;

use App\Models\Stocktake;
use App\Models\StocktakeLine;
use App\Services\Stock\Stocktaker;
use App\Support\CurrentCompany;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use RuntimeException;

/**
 * The count sheet.
 *
 * Written for somebody holding a phone in one hand and a crate in the other:
 * one row per item, one box to type into, and nothing that has to be got right
 * in order. A blank box means "not counted yet" and stays blank — it is never
 * turned into a zero, because the difference between an empty shelf and a
 * shelf nobody reached is the whole reliability of the count.
 */
class Count extends Component
{
    public Stocktake $stocktake;

    /** @var array<string, string> item id => what was counted, as typed */
    public array $counts = [];

    public string $search = '';

    public bool $onlyUncounted = false;

    public function mount(Stocktake $stocktake): void
    {
        Gate::authorize('products.view');

        $this->stocktake = $stocktake;

        foreach ($stocktake->lines as $line) {
            $this->counts[$line->item_id] = $line->counted_quantity === null
                ? ''
                : rtrim(rtrim((string) $line->counted_quantity, '0'), '.');
        }
    }

    public function save(): void
    {
        Gate::authorize('products.adjust-stock');

        try {
            app(Stocktaker::class)->save($this->stocktake, $this->counts, auth()->user());
            $this->stocktake->refresh();
            session()->flash('status', 'Saved. You can carry on counting.');
        } catch (RuntimeException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function post(): void
    {
        Gate::authorize('products.adjust-stock');

        try {
            $taker = app(Stocktaker::class);
            $taker->save($this->stocktake, $this->counts, auth()->user());
            $this->stocktake = $taker->post($this->stocktake, auth()->user());

            session()->flash('status', 'Counted and posted. The shelves and the books now agree.');
        } catch (RuntimeException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function voidCount(): void
    {
        Gate::authorize('products.adjust-stock');

        try {
            $this->stocktake = app(Stocktaker::class)->void($this->stocktake, auth()->user());
            session()->flash('status', 'Voided. The adjustments and the journal entry have been reversed.');
        } catch (RuntimeException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    /** Fill a line with what the ledger already says, for a shelf that matched. */
    public function acceptBook(string $itemId): void
    {
        $line = $this->stocktake->lines->firstWhere('item_id', $itemId);

        if ($line !== null) {
            $this->counts[$itemId] = rtrim(rtrim((string) $line->book_quantity, '0'), '.');
        }
    }

    public function render(): View
    {
        $this->stocktake->loadMissing(['lines.item', 'location:id,name']);

        $lines = $this->stocktake->lines
            ->filter(function (StocktakeLine $line) {
                if ($this->onlyUncounted && $this->typed($line->item_id) !== '') {
                    return false;
                }

                if ($this->search === '') {
                    return true;
                }

                $term = mb_strtolower(trim($this->search));

                return str_contains(mb_strtolower((string) $line->item?->name), $term)
                    || str_contains(mb_strtolower((string) $line->item?->sku), $term);
            })
            ->sortBy(fn (StocktakeLine $line) => mb_strtolower((string) $line->item?->name))
            ->values();

        // Computed from what is typed rather than from what is saved, so the
        // running total moves as somebody counts instead of after they submit.
        $countedValue = 0.0;
        $varianceValue = 0.0;
        $countedLines = 0;

        foreach ($this->stocktake->lines as $line) {
            $typed = $this->typed($line->item_id);
            $quantity = $typed === '' ? (float) $line->book_quantity : (float) $typed;

            $countedValue += $quantity * (float) $line->unit_cost;

            if ($typed !== '') {
                $countedLines++;
                $varianceValue += ($quantity - (float) $line->book_quantity) * (float) $line->unit_cost;
            }
        }

        return view('livewire.stock.count', [
            'lines' => $lines,
            'total' => $this->stocktake->lines->count(),
            'countedLines' => $countedLines,
            'countedValue' => round($countedValue, 2),
            'varianceValue' => round($varianceValue, 2),
            'currency' => app(CurrentCompany::class)->get()?->currency ?? 'XAF',
        ])->layout('components.layouts.app', [
            'title' => 'Count — '.$this->stocktake->reference,
            'active' => 'products',
        ]);
    }

    protected function typed(string $itemId): string
    {
        return trim((string) ($this->counts[$itemId] ?? ''));
    }
}
