<?php

namespace App\Livewire\Orders;

use App\Models\DeliveryNote;
use App\Models\SalesOrder;
use App\Services\Orders\Fulfilment;
use App\Services\Orders\Returns;
use App\Support\CurrentCompany;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use RuntimeException;

/**
 * One order, worked through its life.
 *
 * The two acts that commit anything sit behind their own gates: confirming
 * holds real stock against the order (orders.confirm) and delivering moves
 * it off the shelf (orders.deliver). Every refusal the service makes lands
 * here as a message a person can act on, never a crash.
 */
class Show extends Component
{
    public string $orderId;

    // ── Delivering ──────────────────────────────────────────────────────
    public bool $delivering = false;

    /** @var array<string, string> line id → quantity going out now */
    public array $picks = [];

    // ── Credit control ──────────────────────────────────────────────────
    /** Shown only after a confirm is refused on the customer's credit. */
    public bool $creditBlocked = false;

    public string $creditOverride = '';

    // ── Returning ───────────────────────────────────────────────────────
    /** The delivery note a return is being recorded against, if any. */
    public ?string $returningNoteId = null;

    /** @var array<string, string> delivery note line id → quantity back */
    public array $returnQuantities = [];

    public string $returnReason = '';

    /**
     * Accepts the id under either name: the production route binds
     * `/orders/{order}` while direct component tests pass `orderId`.
     */
    public function mount(?string $orderId = null, ?string $order = null): void
    {
        Gate::authorize('orders.view');

        $this->orderId = $orderId ?? $order ?? '';
        $this->orderId = $this->order()->id;
    }

    protected function order(): SalesOrder
    {
        return SalesOrder::query()
            ->with(['contact', 'lines.item', 'deliveryNotes.lines', 'invoices', 'sourceDocument'])
            ->findOrFail($this->orderId ?? '');
    }

    public function confirm(): void
    {
        Gate::authorize('orders.confirm');

        try {
            $shortages = app(Fulfilment::class)->confirm(
                $this->order(),
                auth()->user(),
                trim($this->creditOverride) ?: null,
            );
        } catch (RuntimeException $e) {
            /*
             * A credit refusal opens the override field rather than dead-
             * ending: the service names the figures, and going ahead anyway
             * requires writing down why — which lands on the order.
             */
            $this->creditBlocked = str_contains($e->getMessage(), 'credit limit');
            $this->addError('order', $e->getMessage());

            return;
        }

        $this->creditBlocked = false;
        $this->creditOverride = '';

        $this->dispatch('toast', message: $shortages === []
            ? 'Confirmed — everything is reserved.'
            : 'Confirmed. Some items are backordered — see the lines.');
    }

    public function reserveBackorders(): void
    {
        Gate::authorize('orders.confirm');

        try {
            $still = app(Fulfilment::class)->reserveBackorders($this->order(), auth()->user());
        } catch (RuntimeException $e) {
            $this->addError('order', $e->getMessage());

            return;
        }

        $this->dispatch('toast', message: $still === []
            ? 'The backorder is now fully reserved.'
            : 'Reserved what arrived — the rest is still short.');
    }

    public function startDelivering(): void
    {
        Gate::authorize('orders.deliver');

        $order = $this->order();
        $this->picks = [];

        foreach ($order->lines as $line) {
            if ((float) $line->quantity_reserved > 0) {
                $this->picks[$line->id] = rtrim(rtrim(number_format((float) $line->quantity_reserved, 3, '.', ''), '0'), '.');
            }
        }

        $this->delivering = true;
    }

    public function deliver(): void
    {
        Gate::authorize('orders.deliver');

        try {
            $note = app(Fulfilment::class)->deliver(
                $this->order(),
                array_map(fn ($q) => $q === '' ? 0.0 : (float) $q, $this->picks),
                auth()->user(),
            );
        } catch (RuntimeException $e) {
            $this->addError('picks', $e->getMessage());

            return;
        }

        $this->delivering = false;
        $this->picks = [];
        $this->dispatch('toast', message: "{$note->number} issued — the stock has moved.");
    }

    public function startReturn(string $noteId): void
    {
        Gate::authorize('orders.deliver');

        $note = $this->order()->deliveryNotes->firstWhere('id', $noteId);

        if ($note === null) {
            return;
        }

        $this->returningNoteId = $note->id;
        $this->returnReason = '';
        $this->returnQuantities = [];

        foreach ($note->lines as $line) {
            $this->returnQuantities[$line->id] = '';
        }
    }

    /**
     * Record goods coming back: restocked through the ordinary ledger and,
     * where they were invoiced, credited through the ordinary credit note.
     * Behind orders.deliver — receiving the shelf is the same trust as
     * moving it.
     */
    public function recordReturn(): void
    {
        Gate::authorize('orders.deliver');

        $note = DeliveryNote::query()->with('lines')->find($this->returningNoteId ?? '');

        if ($note === null) {
            return;
        }

        try {
            $result = app(Returns::class)->record(
                $note,
                array_map(fn ($q) => $q === '' ? 0.0 : (float) $q, $this->returnQuantities),
                auth()->user(),
                trim($this->returnReason),
            );
        } catch (RuntimeException $e) {
            $this->addError('return', $e->getMessage());

            return;
        }

        $this->returningNoteId = null;
        $this->returnQuantities = [];
        $this->returnReason = '';

        $this->dispatch('toast', message: $result['credit_note'] !== null
            ? "Return recorded — the stock is back and {$result['credit_note']->number} credits the customer."
            : 'Return recorded — the stock is back on the shelf.');
    }

    public function invoice(): void
    {
        Gate::authorize('orders.manage');

        try {
            $invoice = app(Fulfilment::class)->invoice($this->order(), auth()->user());
        } catch (RuntimeException $e) {
            $this->addError('order', $e->getMessage());

            return;
        }

        $this->dispatch('toast', message: 'Draft invoice created from the delivered lines — review and issue it from Sales.');
    }

    public function cancel(): void
    {
        Gate::authorize('orders.manage');

        try {
            app(Fulfilment::class)->cancel($this->order(), auth()->user());
        } catch (RuntimeException $e) {
            $this->addError('order', $e->getMessage());

            return;
        }

        $this->dispatch('toast', message: 'Order cancelled — the reserved stock is free again.');
    }

    public function render(): View
    {
        return view('livewire.orders.show', [
            'order' => $this->order(),
            'currency' => app(CurrentCompany::class)->get()?->currency ?? 'XAF',
        ])->layout('components.layouts.app', ['title' => 'Order', 'active' => 'orders']);
    }
}
