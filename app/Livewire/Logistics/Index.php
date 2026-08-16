<?php

namespace App\Livewire\Logistics;

use App\Models\Contact;
use App\Models\FixedAsset;
use App\Models\Shipment;
use App\Models\TripManifest;
use App\Services\Logistics\Dispatch;
use App\Support\CurrentCompany;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use RuntimeException;

/**
 * The dispatch board: what is waiting, what is being loaded, what is on the
 * road.
 *
 * Three questions, three columns. Every button goes through the Dispatch
 * service — a board that wrote `status` itself would move cargo with no
 * event row behind it, and the tracking page would be lying to the customer
 * within the hour.
 */
class Index extends Component
{
    // ── Book a shipment ─────────────────────────────────────────────────
    public bool $booking = false;

    public ?string $senderId = null;

    public ?string $receiverId = null;

    public string $cargo = '';

    public ?string $weightKg = null;

    public ?string $declaredValue = null;

    public string $fromLocation = '';

    public string $toLocation = '';

    public ?string $freightAmount = null;

    // ── Open a manifest ─────────────────────────────────────────────────
    public bool $opening = false;

    public ?string $vehicleId = null;

    public ?string $driverId = null;

    public string $departsOn = '';

    // ── Load a shipment aboard ──────────────────────────────────────────
    /** The manifest whose loading strip is open, if any. */
    public ?string $loading = null;

    public ?string $loadShipmentId = null;

    public function book(): void
    {
        Gate::authorize('logistics.manage');

        $this->validate([
            'senderId' => ['required', 'string'],
            'receiverId' => ['required', 'string'],
            'cargo' => ['required', 'string', 'max:200'],
            'fromLocation' => ['required', 'string', 'max:120'],
            'toLocation' => ['required', 'string', 'max:120'],
            'weightKg' => ['nullable', 'numeric', 'min:0'],
            'declaredValue' => ['nullable', 'numeric', 'min:0'],
            'freightAmount' => ['nullable', 'numeric', 'min:0'],
        ]);

        try {
            app(Dispatch::class)->book([
                'sender_id' => $this->senderId,
                'receiver_id' => $this->receiverId,
                'cargo_description' => $this->cargo,
                'weight_kg' => $this->weightKg !== null && $this->weightKg !== '' ? (float) $this->weightKg : null,
                'declared_value' => $this->declaredValue !== null && $this->declaredValue !== '' ? (float) $this->declaredValue : null,
                'from_location' => $this->fromLocation,
                'to_location' => $this->toLocation,
                'freight_amount' => $this->freightAmount !== null && $this->freightAmount !== '' ? (float) $this->freightAmount : null,
            ], auth()->user());
        } catch (RuntimeException $e) {
            $this->addError('cargo', $e->getMessage());

            return;
        }

        $this->reset('booking', 'senderId', 'receiverId', 'cargo', 'weightKg', 'declaredValue', 'fromLocation', 'toLocation', 'freightAmount');
        $this->dispatch('toast', message: 'Shipment booked.');
    }

    public function openManifest(): void
    {
        Gate::authorize('logistics.manage');

        $this->validate([
            'vehicleId' => ['required', 'string'],
            'departsOn' => ['required', 'date'],
            'driverId' => ['nullable', 'string'],
        ]);

        try {
            app(Dispatch::class)->openManifest(
                FixedAsset::findOrFail($this->vehicleId),
                $this->driverId ? \App\Models\User::find((int) $this->driverId) : null,
                $this->departsOn,
                auth()->user(),
            );
        } catch (RuntimeException $e) {
            $this->addError('vehicleId', $e->getMessage());

            return;
        }

        $this->reset('opening', 'vehicleId', 'driverId', 'departsOn');
        $this->dispatch('toast', message: 'Manifest opened.');
    }

    public function load(string $manifestId): void
    {
        Gate::authorize('logistics.manage');

        $this->validate(['loadShipmentId' => ['required', 'string']]);

        try {
            app(Dispatch::class)->load(
                Shipment::findOrFail($this->loadShipmentId),
                TripManifest::findOrFail($manifestId),
                auth()->user(),
            );
        } catch (RuntimeException $e) {
            $this->addError('loadShipmentId', $e->getMessage());

            return;
        }

        $this->reset('loading', 'loadShipmentId');
        $this->dispatch('toast', message: 'Shipment loaded.');
    }

    /**
     * The one money-committing act on this board: dispatching commits the
     * vehicle for the day and turns every booking aboard into a promise on
     * the public tracking page. Its own ability, following the house
     * doctrine of splitting the committing act out of `manage`.
     */
    public function dispatchManifest(string $manifestId): void
    {
        Gate::authorize('logistics.dispatch');

        try {
            app(Dispatch::class)->dispatch(TripManifest::findOrFail($manifestId), auth()->user());
        } catch (RuntimeException $e) {
            $this->addError('loadShipmentId', $e->getMessage());

            return;
        }

        $this->dispatch('toast', message: 'Manifest dispatched.');
    }

    public function render(): View
    {
        Gate::authorize('logistics.view');

        $company = app(CurrentCompany::class)->get();

        return view('livewire.logistics.index', [
            'unassigned' => Shipment::query()
                ->with(['sender', 'receiver'])
                ->where('status', 'booked')
                ->latest()
                ->get(),
            'openManifests' => TripManifest::query()
                ->with(['vehicle.vehicle', 'driver', 'shipments'])
                ->where('status', 'open')
                ->orderBy('departs_on')
                ->get(),
            'inTransit' => TripManifest::query()
                ->with(['vehicle.vehicle', 'driver', 'shipments.receiver'])
                ->where('status', 'dispatched')
                ->orderBy('departs_on')
                ->get(),
            'contacts' => Contact::query()->orderBy('name')->limit(200)->get(['id', 'name']),
            // Only assets with a vehicle record can carry a manifest.
            'vehicles' => FixedAsset::query()->has('vehicle')->orderBy('name')->get(),
            'people' => $company?->users()->orderBy('name')->get(['users.id', 'users.name']) ?? collect(),
        ])->layout('components.layouts.app', ['title' => 'Dispatch', 'active' => 'logistics']);
    }
}
