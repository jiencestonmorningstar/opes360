<?php

namespace App\Livewire\Stock;

use App\Models\StockLocation;
use App\Models\Stocktake;
use App\Services\Stock\Stocktaker;
use App\Services\Stock\StockValuation;
use App\Support\Accounting\ChartOfAccounts;
use App\Support\CurrentCompany;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use RuntimeException;

/**
 * What the stock is worth, and whether the books agree.
 *
 * Two numbers side by side: what the shelves are worth at weighted average
 * cost, and what account 31 is carrying. They drift apart the moment anything
 * is bought or sold, and closing that gap is what a count is for — so the
 * difference is the screen's headline rather than a figure somebody has to
 * work out.
 */
class Valuation extends Component
{
    public ?string $locationId = null;

    public string $countedOn = '';

    public bool $starting = false;

    public function mount(): void
    {
        Gate::authorize('products.view');

        $this->countedOn = now()->toDateString();
    }

    public function startCount(): void
    {
        Gate::authorize('products.adjust-stock');

        $this->validate([
            'countedOn' => ['required', 'date'],
            'locationId' => ['nullable', 'string'],
        ]);

        $company = app(CurrentCompany::class)->get();

        if ($company === null) {
            return;
        }

        try {
            $stocktake = app(Stocktaker::class)->start(
                $company,
                $this->locationId ? StockLocation::query()->find($this->locationId) : null,
                $this->countedOn,
                auth()->user(),
            );

            $this->redirectRoute('products.stock.count', $stocktake, navigate: true);
        } catch (RuntimeException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function render(): View
    {
        $company = app(CurrentCompany::class)->get();
        $valuation = app(StockValuation::class);

        $holdings = $company ? $valuation->holdings($company) : collect();
        $onShelf = round($holdings->sum('value'), 2);
        $inBooks = $this->stockAccountBalance();

        return view('livewire.stock.valuation', [
            'holdings' => $holdings->filter(fn (array $row) => abs($row['quantity']) > 0.0005)->values(),
            'onShelf' => $onShelf,
            'inBooks' => $inBooks,
            'difference' => round($onShelf - $inBooks, 2),
            /*
             * Stock nobody has ever priced. Shown by name rather than as a
             * count, because the fix is per item and "seven items have no
             * cost" sends nobody anywhere.
             */
            'unpriced' => $holdings
                ->filter(fn (array $row) => $row['unit_cost'] <= 0 && abs($row['quantity']) > 0.0005)
                ->map(fn (array $row) => $row['item'])
                ->values(),
            'locations' => StockLocation::query()->where('active', true)->orderBy('name')->get(),
            'counts' => Stocktake::query()
                ->with('location:id,name')
                ->orderByDesc('counted_on')
                ->orderByDesc('created_at')
                ->limit(12)
                ->get(),
            'currency' => $company?->currency ?? 'XAF',
        ])->layout('components.layouts.app', ['title' => 'Stock value', 'active' => 'products']);
    }

    /** What account 31 carries today, or null when the business keeps no books. */
    protected function stockAccountBalance(): float
    {
        $company = app(CurrentCompany::class)->get();
        $account = $company ? ChartOfAccounts::account($company, 'stock') : null;

        if ($account === null) {
            return 0.0;
        }

        $totals = DB::table('journal_lines')
            ->where('ledger_account_id', $account->id)
            ->selectRaw('COALESCE(SUM(debit), 0) AS debit, COALESCE(SUM(credit), 0) AS credit')
            ->first();

        return round((float) $totals->debit - (float) $totals->credit, 2);
    }
}
