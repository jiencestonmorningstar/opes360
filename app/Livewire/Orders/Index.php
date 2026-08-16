<?php

namespace App\Livewire\Orders;

use App\Models\Contact;
use App\Models\Item;
use App\Models\SalesOrder;
use App\Services\Orders\Fulfilment;
use App\Support\CurrentCompany;
use App\Support\FulfilmentBoard;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;
use RuntimeException;

/**
 * The order book: what customers are owed, and a door to raise more.
 *
 * The fulfilment board sits on top — promised, shippable today, short —
 * because that is the question a despatch desk opens this screen to ask.
 * Everything that commits or moves stock lives on the Show screen behind its
 * own gates; this one drafts.
 */
class Index extends Component
{
    #[Url]
    public string $filter = 'open'; // open|all

    // ── Draft form ──────────────────────────────────────────────────────
    public bool $drafting = false;

    public ?string $contactId = null;

    public string $promisedDate = '';

    public string $notes = '';

    /** @var array<int, array{item_id: string, quantity: string, unit_price: string}> */
    public array $lines = [];

    public function mount(): void
    {
        Gate::authorize('orders.view');
    }

    public function startDrafting(): void
    {
        Gate::authorize('orders.manage');

        $this->drafting = true;
        $this->contactId = null;
        $this->promisedDate = '';
        $this->notes = '';
        $this->lines = [['item_id' => '', 'quantity' => '', 'unit_price' => '']];
    }

    public function addLine(): void
    {
        $this->lines[] = ['item_id' => '', 'quantity' => '', 'unit_price' => ''];
    }

    public function removeLine(int $index): void
    {
        unset($this->lines[$index]);
        $this->lines = array_values($this->lines);
    }

    public function save(): void
    {
        Gate::authorize('orders.manage');

        $this->validate([
            'contactId' => ['required', 'string'],
            'promisedDate' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'string'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0'],
        ], [
            'contactId.required' => 'Choose a customer.',
            'lines.*.item_id.required' => 'Choose a product.',
            'lines.*.quantity.required' => 'How many?',
        ]);

        try {
            app(Fulfilment::class)->create([
                'contact_id' => $this->contactId,
                'promised_date' => $this->promisedDate ?: null,
                'notes' => $this->notes ?: null,
                'lines' => array_map(fn (array $line) => [
                    'item_id' => $line['item_id'],
                    'quantity' => (float) $line['quantity'],
                    'unit_price' => $line['unit_price'] !== '' ? (float) $line['unit_price'] : null,
                ], $this->lines),
            ], auth()->user());
        } catch (RuntimeException $e) {
            $this->addError('lines', $e->getMessage());

            return;
        }

        $this->drafting = false;
        $this->dispatch('toast', message: 'Order drafted. Confirm it to reserve the stock.');
    }

    public function render(): View
    {
        $company = app(CurrentCompany::class)->get();

        $orders = SalesOrder::query()
            ->with(['contact', 'lines'])
            ->when($this->filter === 'open', fn ($q) => $q->whereNotIn('status', [
                SalesOrder::STATUS_INVOICED,
                SalesOrder::STATUS_CANCELLED,
            ]))
            ->latest()
            ->limit(150)
            ->get();

        return view('livewire.orders.index', [
            'orders' => $orders,
            'board' => (new FulfilmentBoard($company))->rows(),
            'customers' => Contact::query()->orderBy('name')->get(['id', 'name']),
            'products' => Item::query()->active()->orderBy('name')->get(['id', 'name', 'sku', 'price']),
            'currency' => $company?->currency ?? 'XAF',
        ])->layout('components.layouts.app', ['title' => 'Orders', 'active' => 'orders']);
    }
}
