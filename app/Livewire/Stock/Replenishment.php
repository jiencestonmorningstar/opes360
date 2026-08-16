<?php

namespace App\Livewire\Stock;

use App\Models\Contact;
use App\Models\Item;
use App\Models\ItemSupplier;
use App\Models\PurchaseRequisition;
use App\Services\Procurement\Replenisher;
use App\Support\CurrentCompany;
use App\Support\Replenishment as ReplenishmentModel;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use RuntimeException;

/**
 * What needs ordering, and one button to ask for it properly.
 *
 * The list is App\Support\Replenishment verbatim. The button is
 * Replenisher, which raises ordinary draft requisitions through the existing
 * procurement service — this screen holds no power to commit money, and the
 * requisitions it raises are approved (or not) by the same workflow as
 * everything else.
 */
class Replenishment extends Component
{
    /** @var array<string, bool> item id => checked */
    public array $selected = [];

    // ── Inline supply-details editor ──────────────────────────────────────
    //
    // Who supplies this, how long they take, what they last charged, and how
    // full the shelf should be. Edited here rather than on the product form
    // because the product form is a single-payload offline form for catalogue
    // facts, and because this screen is where a missing supplier or lead time
    // is actually felt — "No supplier on file" and its fix belong on the same
    // screen. Gated products.update: these are facts about the product, the
    // same trust as editing its cost.

    public ?string $editingId = null;

    public string $editSupplierId = '';

    public string $editLeadDays = '';

    public string $editLastPrice = '';

    public string $editMaxLevel = '';

    public function mount(): void
    {
        Gate::authorize('products.view');
    }

    public function startEdit(string $itemId): void
    {
        Gate::authorize('products.update');

        $item = Item::findOrFail($itemId);
        $link = $item->preferredSupplierLink();

        $this->resetErrorBag();
        $this->editingId = $item->id;
        $this->editSupplierId = (string) ($link?->supplier_id ?? '');
        $this->editLeadDays = $link ? (string) $link->lead_days : '';
        $this->editLastPrice = $link?->last_price !== null ? (string) $link->last_price : '';
        $this->editMaxLevel = $item->max_level !== null ? (string) $item->max_level : '';
    }

    public function cancelEdit(): void
    {
        $this->reset(['editingId', 'editSupplierId', 'editLeadDays', 'editLastPrice', 'editMaxLevel']);
    }

    public function saveEdit(): void
    {
        Gate::authorize('products.update');

        $item = Item::findOrFail($this->editingId);

        $this->validate([
            'editSupplierId' => ['nullable', 'string'],
            'editLeadDays' => ['nullable', 'integer', 'min:0', 'max:365'],
            'editLastPrice' => ['nullable', 'numeric', 'gte:0'],
            'editMaxLevel' => ['nullable', 'numeric', 'gte:0'],
        ]);

        $item->update([
            'max_level' => $this->editMaxLevel !== '' ? (float) $this->editMaxLevel : null,
        ]);

        if ($this->editSupplierId !== '') {
            // Scoped find: a supplier id from another tenant must 404, not link.
            $supplier = Contact::findOrFail($this->editSupplierId);

            $link = ItemSupplier::firstOrNew([
                'item_id' => $item->id,
                'supplier_id' => $supplier->id,
            ]);

            $link->fill([
                'company_id' => $item->company_id,
                'lead_days' => $this->editLeadDays !== '' ? (int) $this->editLeadDays : ($link->lead_days ?? 7),
                'last_price' => $this->editLastPrice !== '' ? (float) $this->editLastPrice : $link->last_price,
                'is_preferred' => true,
            ])->save();

            // One preferred supplier per item — the plan needs a single answer.
            ItemSupplier::query()
                ->where('item_id', $item->id)
                ->whereKeyNot($link->id)
                ->update(['is_preferred' => false]);
        }

        $this->cancelEdit();
    }

    public function accept(): void
    {
        Gate::authorize('procurement.requisition-manage');

        $ids = array_keys(array_filter($this->selected));

        if ($ids === []) {
            $this->addError('selected', 'Tick what you want to order first.');

            return;
        }

        try {
            $requisitions = app(Replenisher::class)->accept($ids, auth()->user());
        } catch (RuntimeException $e) {
            $this->addError('selected', $e->getMessage());

            return;
        }

        $this->selected = [];

        session()->flash('status', sprintf(
            '%d draft %s raised — %s. Submit %s from Procurement when ready.',
            $requisitions->count(),
            $requisitions->count() === 1 ? 'requisition' : 'requisitions',
            $requisitions->map(fn (PurchaseRequisition $r) => $r->number)->implode(', '),
            $requisitions->count() === 1 ? 'it' : 'them',
        ));
    }

    public function render(): View
    {
        $company = app(CurrentCompany::class)->get();

        $rows = $company ? (new ReplenishmentModel($company))->rows() : collect();

        return view('livewire.stock.replenishment', [
            'rows' => $rows,
            'urgent' => $rows->filter(fn (array $row) => $row['will_run_out'])->count(),
            'currency' => $company?->currency ?? 'XAF',
            'canAccept' => Gate::allows('procurement.requisition-manage'),
            'suppliers' => $this->editingId !== null && $company
                ? Contact::query()->where('type', 'supplier')->orderBy('name')->get(['id', 'name'])
                : collect(),
        ])->layout('components.layouts.app', ['title' => 'Replenishment', 'active' => 'products']);
    }
}
