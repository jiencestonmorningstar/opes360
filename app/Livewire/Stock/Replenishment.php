<?php

namespace App\Livewire\Stock;

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

    public function mount(): void
    {
        Gate::authorize('products.view');
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
        ])->layout('components.layouts.app', ['title' => 'Replenishment', 'active' => 'products']);
    }
}
