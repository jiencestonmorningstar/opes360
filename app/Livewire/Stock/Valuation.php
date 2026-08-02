<?php

namespace App\Livewire\Stock;

use App\Models\Contact;
use App\Models\Item;
use App\Models\StockLocation;
use App\Models\Stocktake;
use App\Services\Stock\DeliveryReceiver;
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

    // ── A delivery arriving ─────────────────────────────────────────────
    public bool $receiving = false;

    public string $receivedOn = '';

    public ?string $supplierId = null;

    public string $deliveryReference = '';

    public bool $recordExpense = true;

    public string $deliveryVatRate = '0';

    /** @var array<int, array{item_id: ?string, quantity: string, unit_cost: string}> */
    public array $deliveryLines = [];

    public function mount(): void
    {
        Gate::authorize('products.view');

        $this->countedOn = now()->toDateString();
        $this->receivedOn = now()->toDateString();
    }

    public function startReceiving(): void
    {
        Gate::authorize('products.adjust-stock');

        $this->resetValidation();
        $this->receivedOn = now()->toDateString();
        $this->deliveryReference = '';
        $this->supplierId = null;
        $this->recordExpense = true;
        $this->deliveryVatRate = (string) (app(CurrentCompany::class)->get()?->vat_registered ? '0.1925' : '0');
        $this->deliveryLines = [['item_id' => null, 'quantity' => '', 'unit_cost' => '']];
        $this->receiving = true;
    }

    public function addDeliveryLine(): void
    {
        $this->deliveryLines[] = ['item_id' => null, 'quantity' => '', 'unit_cost' => ''];
    }

    public function removeDeliveryLine(int $index): void
    {
        unset($this->deliveryLines[$index]);
        $this->deliveryLines = array_values($this->deliveryLines);

        if ($this->deliveryLines === []) {
            $this->deliveryLines = [['item_id' => null, 'quantity' => '', 'unit_cost' => '']];
        }
    }

    /**
     * Take the delivery in.
     *
     * The unit cost is what makes this worth having: it is the only figure the
     * weighted average is built from, and without a way to record one a
     * business's whole valuation rested on the catalogue's planning price.
     */
    public function receiveDelivery(): void
    {
        Gate::authorize('products.adjust-stock');

        $this->validate([
            'receivedOn' => ['required', 'date'],
            'deliveryLines' => ['required', 'array', 'min:1'],
            'deliveryLines.*.item_id' => ['nullable', 'string'],
            'deliveryLines.*.quantity' => ['nullable', 'numeric', 'min:0'],
            'deliveryLines.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'deliveryReference' => ['nullable', 'string', 'max:60'],
            'deliveryVatRate' => ['required', 'numeric', 'min:0', 'max:1'],
        ], [
            'deliveryLines.*.quantity.numeric' => 'Quantities are numbers.',
        ]);

        $company = app(CurrentCompany::class)->get();

        if ($company === null) {
            return;
        }

        try {
            $result = app(DeliveryReceiver::class)->receive(
                $company,
                $this->deliveryLines,
                [
                    'received_on' => $this->receivedOn,
                    'supplier_id' => $this->supplierId,
                    'reference' => $this->deliveryReference ?: null,
                    'location_id' => $this->locationId,
                    'record_expense' => $this->recordExpense,
                    'vat_rate' => (float) $this->deliveryVatRate,
                    'payment_method' => $this->recordExpense ? 'bank' : null,
                ],
                auth()->user(),
            );
        } catch (RuntimeException $e) {
            $this->addError('deliveryLines', $e->getMessage());

            return;
        }

        $this->receiving = false;

        session()->flash('status', sprintf(
            '%d %s received.%s',
            $result['movements'],
            $result['movements'] === 1 ? 'line' : 'lines',
            $result['expense'] !== null ? ' The bill is in the books too.' : ''
        ));
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
            // Only what can actually be received: a service has nothing to put
            // on a shelf, and an untracked product has no ledger to write to.
            'trackedItems' => Item::query()
                ->where('type', 'product')->where('track_stock', true)->where('is_active', true)
                ->orderBy('name')->get(['id', 'name', 'sku', 'cost']),
            'suppliers' => Contact::query()
                ->whereIn('type', ['supplier', 'vendor'])->orderBy('name')->get(['id', 'name']),
            'recentDeliveries' => $company ? app(DeliveryReceiver::class)->recent($company) : collect(),
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
