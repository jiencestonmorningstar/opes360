<?php

namespace App\Livewire\Logistics;

use App\Models\Shipment;
use App\Services\Documents\DocumentSignatureRequests;
use App\Services\Logistics\Dispatch;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use RuntimeException;

/**
 * One shipment: its history, its proof of delivery, its invoice.
 *
 * The invoice and the POD are links into their own modules — the invoice is
 * an ordinary Document in accounts receivable, the POD a BusinessDocument in
 * the papers module with the one signature flow behind it. This screen shows
 * where they are; it renders neither.
 */
class Show extends Component
{
    public Shipment $shipment;

    public bool $withPod = true;

    public function mount(Shipment $shipment): void
    {
        $this->shipment = $shipment;
    }

    public function deliver(): void
    {
        Gate::authorize('logistics.manage');

        try {
            app(Dispatch::class)->deliver($this->shipment, auth()->user(), $this->withPod);
        } catch (RuntimeException $e) {
            $this->addError('shipment', $e->getMessage());

            return;
        }

        $this->shipment->refresh();
        $this->dispatch('toast', message: 'Shipment delivered.');
    }

    public function cancel(): void
    {
        Gate::authorize('logistics.manage');

        try {
            app(Dispatch::class)->cancel($this->shipment, auth()->user());
        } catch (RuntimeException $e) {
            $this->addError('shipment', $e->getMessage());

            return;
        }

        $this->shipment->refresh();
        $this->dispatch('toast', message: 'Booking cancelled.');
    }

    public function invoice(): void
    {
        Gate::authorize('logistics.manage');

        try {
            app(Dispatch::class)->draftInvoice($this->shipment, auth()->user());
        } catch (RuntimeException $e) {
            $this->addError('shipment', $e->getMessage());

            return;
        }

        $this->shipment->refresh();
        $this->dispatch('toast', message: 'Freight invoice drafted.');
    }

    public function render(): View
    {
        Gate::authorize('logistics.view');

        $this->shipment->load(['sender', 'receiver', 'events', 'invoice', 'podDocument.signatures']);

        return view('livewire.logistics.show', [
            'podStatus' => $this->shipment->podDocument === null
                ? null
                : app(DocumentSignatureRequests::class)->status($this->shipment->podDocument),
            'openManifest' => $this->shipment->openManifest(),
        ])->layout('components.layouts.app', ['title' => $this->shipment->reference, 'active' => 'logistics']);
    }
}
