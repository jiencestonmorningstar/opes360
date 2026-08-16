<?php

namespace App\Livewire\Procurement;

use App\Models\CostCentre;
use App\Models\PurchaseRequisition;
use App\Services\Procurement\Requisitions as RequisitionService;
use App\Support\CurrentCompany;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;
use RuntimeException;

/**
 * Asking the business to buy something.
 *
 * This screen only ever asks. The answer is given somewhere else entirely — in
 * the approval inbox at /actions, by whoever the workflow rules name — which is
 * why there is no approve button anywhere below and no permission that would
 * turn one on. Submitting hands the request over; after that the screen's job
 * is to say honestly where it got to.
 */
class Requisitions extends Component
{
    #[Url]
    public string $filter = 'open'; // open|waiting|decided|all

    /** The requisition whose lines are on screen, if any. */
    #[Url]
    public ?string $viewing = null;

    public bool $raising = false;

    public string $title = '';

    public string $neededBy = '';

    public string $justification = '';

    public ?string $costCentreId = null;

    public string $notes = '';

    /** @var array<int, array<string, string>> */
    public array $lines = [];

    public function mount(): void
    {
        Gate::authorize('procurement.requisition-view');
    }

    public function startRaising(): void
    {
        Gate::authorize('procurement.requisition-manage');

        $this->reset(['title', 'justification', 'notes', 'costCentreId']);
        $this->resetValidation();
        $this->neededBy = now()->addWeek()->toDateString();
        $this->lines = [$this->blankLine()];
        $this->raising = true;
    }

    public function addLine(): void
    {
        $this->lines[] = $this->blankLine();
    }

    public function removeLine(int $index): void
    {
        unset($this->lines[$index]);

        // Reindexed because the service reads the lines in order and a gap in
        // the keys would silently reorder what somebody typed.
        $this->lines = array_values($this->lines);

        if ($this->lines === []) {
            $this->lines = [$this->blankLine()];
        }
    }

    public function cancel(): void
    {
        $this->raising = false;
        $this->resetValidation();
    }

    public function save(): void
    {
        Gate::authorize('procurement.requisition-manage');

        $this->validate([
            'title' => ['required', 'string', 'max:180'],
            'neededBy' => ['nullable', 'date'],
            'justification' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'lines.*.estimatedUnitPrice' => ['nullable', 'numeric', 'min:0'],
        ], [
            'title.required' => 'What is being asked for?',
            'lines.*.description.required' => 'Every line needs to say what is being asked for.',
            'lines.*.quantity.min' => 'A line has to ask for more than nothing.',
        ]);

        try {
            $requisition = app(RequisitionService::class)->create([
                'title' => $this->title,
                'needed_by' => $this->neededBy ?: null,
                'justification' => $this->justification ?: null,
                'cost_centre_id' => $this->costCentreId ?: null,
                'notes' => $this->notes ?: null,
                'lines' => collect($this->lines)->map(fn (array $line) => [
                    'description' => $line['description'],
                    'quantity' => (float) $line['quantity'],
                    'unit' => $line['unit'] ?: 'unit',
                    // Named an estimate all the way through: nobody has quoted
                    // yet, and a figure that looks like a price would be read
                    // as one by whoever is asked to approve it.
                    'estimated_unit_price' => (float) ($line['estimatedUnitPrice'] ?: 0),
                ])->all(),
            ], auth()->user());
        } catch (RuntimeException $e) {
            $this->addError('form', $e->getMessage());

            return;
        }

        $this->raising = false;
        $this->viewing = $requisition->id;

        session()->flash('status', "{$requisition->number} raised. Submit it when it is ready.");
    }

    /**
     * Hand it to whoever the workflow asks.
     *
     * This is the only decision-shaped control on the screen, and it decides
     * nothing: it starts the approval and stops.
     */
    public function submit(string $id): void
    {
        Gate::authorize('procurement.requisition-manage');

        $requisition = PurchaseRequisition::query()->findOrFail($id);

        try {
            app(RequisitionService::class)->submit($requisition, null, auth()->user());
        } catch (RuntimeException $e) {
            $this->addError('submit', $e->getMessage());

            return;
        }

        session()->flash('status', "{$requisition->number} is with its approver now.");
    }

    public function view(?string $id): void
    {
        $this->viewing = $this->viewing === $id ? null : $id;
        $this->resetValidation();
    }

    /** @return array<string, string> */
    protected function blankLine(): array
    {
        return ['description' => '', 'quantity' => '1', 'unit' => 'unit', 'estimatedUnitPrice' => ''];
    }

    public function render(): View
    {
        $requisitions = PurchaseRequisition::query()
            ->when($this->filter === 'open', fn ($q) => $q->whereIn('status', ['draft', 'returned']))
            ->when($this->filter === 'waiting', fn ($q) => $q->where('status', 'submitted'))
            ->when($this->filter === 'decided', fn ($q) => $q
                ->whereIn('status', ['approved', 'rejected', 'sourcing', 'ordered', 'cancelled']))
            ->with(['lines', 'creator', 'costCentre', 'purchaseOrder'])
            ->latest('created_at')
            ->get();

        return view('livewire.procurement.requisitions', [
            'requisitions' => $requisitions,
            'statuses' => PurchaseRequisition::STATUSES,
            'costCentres' => CostCentre::query()->orderBy('name')->get(['id', 'name']),
            'currency' => app(CurrentCompany::class)->get()?->currency ?? 'XAF',
        ])->layout('components.layouts.app', [
            'title' => 'Requisitions',
            'active' => 'procurement',
        ]);
    }
}
