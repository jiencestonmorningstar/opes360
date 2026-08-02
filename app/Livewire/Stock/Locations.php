<?php

namespace App\Livewire\Stock;

use App\Models\Item;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Services\Stock\LocationLedger;
use App\Support\CurrentCompany;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;
use RuntimeException;

/**
 * Where the stock is.
 *
 * A shop, a warehouse and a van are three different answers to "how many have
 * we got", and a business running out at the counter while a case sits in the
 * store room is the problem this exists to make visible.
 */
class Locations extends Component
{
    #[Url]
    public ?string $locationId = null;

    public bool $adding = false;

    public bool $transferring = false;

    // ── New location ────────────────────────────────────────────────────
    public string $name = '';

    public string $code = '';

    public string $kind = 'shop';

    public string $city = '';

    public string $manager = '';

    // ── Transfer ────────────────────────────────────────────────────────
    public ?string $fromId = null;

    public ?string $toId = null;

    public string $movedOn = '';

    public string $transferNote = '';

    /** @var array<int, array{item_id: ?string, quantity: string}> */
    public array $transferLines = [];

    public function mount(): void
    {
        Gate::authorize('products.manage-locations');

        $this->movedOn = now()->toDateString();
        $this->locationId ??= StockLocation::query()
            ->where('active', true)
            ->orderByDesc('is_default')
            ->value('id');
    }

    public function startAdding(): void
    {
        Gate::authorize('products.manage-locations');

        $this->reset(['name', 'code', 'city', 'manager']);
        $this->resetValidation();
        $this->kind = 'shop';
        $this->adding = true;
    }

    public function save(): void
    {
        Gate::authorize('products.manage-locations');

        $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'code' => ['nullable', 'string', 'max:20'],
            'kind' => ['required', 'in:'.implode(',', array_keys(StockLocation::KINDS))],
            'city' => ['nullable', 'string', 'max:80'],
            'manager' => ['nullable', 'string', 'max:120'],
        ], [
            'name.required' => 'Give the place a name people will recognise.',
        ]);

        $company = app(CurrentCompany::class)->get();
        $first = StockLocation::query()->count() === 0;

        $location = StockLocation::create([
            'company_id' => $company->id,
            'name' => trim($this->name),
            'code' => $this->code ?: null,
            'kind' => $this->kind,
            'city' => $this->city ?: null,
            'manager' => $this->manager ?: null,
            'is_default' => $first,
            'active' => true,
        ]);

        $this->adding = false;
        $this->locationId ??= $location->id;

        session()->flash('status', $first
            ? 'First location added. Stock recorded from now on can be attributed to it.'
            : 'Location added.');
    }

    public function makeDefault(string $id): void
    {
        Gate::authorize('products.manage-locations');

        StockLocation::query()->update(['is_default' => false]);
        StockLocation::query()->whereKey($id)->update(['is_default' => true]);

        session()->flash('status', 'Default location changed.');
    }

    // ── Transfers ───────────────────────────────────────────────────────

    public function startTransfer(): void
    {
        Gate::authorize('products.manage-locations');

        $this->resetValidation();
        $this->fromId = $this->locationId;
        $this->toId = null;
        $this->movedOn = now()->toDateString();
        $this->transferNote = '';
        $this->transferLines = [['item_id' => null, 'quantity' => '']];
        $this->transferring = true;
    }

    public function addTransferLine(): void
    {
        $this->transferLines[] = ['item_id' => null, 'quantity' => ''];
    }

    public function removeTransferLine(int $index): void
    {
        unset($this->transferLines[$index]);
        $this->transferLines = array_values($this->transferLines);

        if ($this->transferLines === []) {
            $this->transferLines = [['item_id' => null, 'quantity' => '']];
        }
    }

    public function saveTransfer(): void
    {
        Gate::authorize('products.manage-locations');

        $this->validate([
            'fromId' => ['required', 'string'],
            'toId' => ['required', 'string', 'different:fromId'],
            'movedOn' => ['required', 'date'],
            'transferLines' => ['required', 'array', 'min:1'],
            'transferLines.*.item_id' => ['required', 'string'],
            'transferLines.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'transferNote' => ['nullable', 'string', 'max:300'],
        ], [
            'toId.different' => 'Stock cannot be transferred to where it already is.',
            'transferLines.*.item_id.required' => 'Choose an item on every line.',
            'transferLines.*.quantity.min' => 'A transfer of nothing is not a transfer.',
        ]);

        try {
            app(LocationLedger::class)->transfer(
                StockLocation::query()->findOrFail($this->fromId),
                StockLocation::query()->findOrFail($this->toId),
                array_map(fn (array $l) => [
                    'item_id' => $l['item_id'],
                    'quantity' => (float) $l['quantity'],
                ], $this->transferLines),
                auth()->user(),
                $this->movedOn,
                $this->transferNote ?: null,
            );

            $this->transferring = false;
            session()->flash('status', 'Stock moved.');
        } catch (RuntimeException $e) {
            $this->addError('transferLines', $e->getMessage());
        }
    }

    public function render(): View
    {
        $locations = StockLocation::query()->orderByDesc('is_default')->orderBy('name')->get();
        $selected = $this->locationId ? $locations->firstWhere('id', $this->locationId) : $locations->first();

        $ledger = app(LocationLedger::class);

        return view('livewire.stock.locations', [
            'locations' => $locations,
            'selected' => $selected,
            'contents' => $selected ? $ledger->contentsOf($selected) : collect(),
            'items' => Item::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'sku', 'track_stock']),
            'transfers' => StockTransfer::query()
                ->with(['from:id,name', 'to:id,name', 'lines'])
                ->orderByDesc('moved_on')
                ->orderByDesc('created_at')
                ->limit(10)
                ->get(),
            /*
             * Stock recorded before the business had locations, or by a screen
             * that does not ask. Shown rather than hidden: a business whose
             * per-location figures do not add up to its total needs to know
             * where the rest is, and the honest answer is "unattributed".
             */
            'unattributed' => StockMovement::query()
                ->whereNull('stock_location_id')
                ->sum('quantity'),
            'currency' => app(CurrentCompany::class)->get()?->currency ?? 'XAF',
        ])->layout('components.layouts.app', ['title' => 'Stock locations', 'active' => 'products']);
    }
}
