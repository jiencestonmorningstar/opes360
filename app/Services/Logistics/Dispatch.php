<?php

namespace App\Services\Logistics;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Contact;
use App\Models\Document;
use App\Models\DocumentLine;
use App\Models\FixedAsset;
use App\Models\Shipment;
use App\Models\TripManifest;
use App\Models\User;
use App\Models\VehicleTrip;
use App\Services\Documents\DocumentSignatureRequests;
use App\Models\BusinessDocument;
use App\Support\UniqueId;
use App\Support\Vat;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The one path a shipment's status moves along.
 *
 * Booked, loaded, in transit, delivered — every turn of that dial happens
 * here and writes a ShipmentEvent behind it, because the event history is
 * what the public tracking page shows and what the office argues from. A
 * screen that wrote `status` itself would move cargo with no story behind it.
 *
 * Everything with a home elsewhere stays there: the vehicle is a FixedAsset,
 * the freight invoice goes out through the ordinary Document tables, the POD
 * signature runs through DocumentSignatureRequests, and the closed manifest's
 * journey lands in the fleet's own VehicleTrip log.
 */
class Dispatch
{
    /**
     * Take a booking. The shipment starts life `booked`, with the tracking
     * token minted immediately — the customer gets their link at the counter,
     * not when the van leaves.
     *
     * @param array{
     *     sender_id: string, receiver_id: string, cargo_description: string,
     *     from_location: string, to_location: string,
     *     weight_kg?: float|string|null, declared_value?: float|string|null,
     *     freight_amount?: float|string|null,
     * } $attributes
     */
    public function book(array $attributes, User $by): Shipment
    {
        foreach (['sender_id', 'receiver_id'] as $party) {
            if (Contact::find($attributes[$party] ?? null) === null) {
                throw new RuntimeException('A shipment needs a sender and a receiver from the customer book.');
            }
        }

        return DB::transaction(function () use ($attributes, $by) {
            // Status set explicitly, never left to a column default: create()
            // does not re-fetch the row, so a database default would leave the
            // in-memory model reading null.
            $shipment = Shipment::create([
                'reference' => $this->nextReference('SHP'),
                'sender_id' => $attributes['sender_id'],
                'receiver_id' => $attributes['receiver_id'],
                'cargo_description' => $attributes['cargo_description'],
                'weight_kg' => $attributes['weight_kg'] ?? null,
                'declared_value' => $attributes['declared_value'] ?? null,
                'from_location' => $attributes['from_location'],
                'to_location' => $attributes['to_location'],
                'freight_amount' => $attributes['freight_amount'] ?? null,
                'status' => 'booked',
                'tracking_token' => Shipment::newTrackingToken(),
                'created_by' => $by->id,
            ]);

            $this->record($shipment, 'booked', 'Booking received', $by);

            $shipment->emitDomainEvent('logistics.shipment.booked', [
                'shipment_id' => $shipment->id,
                'reference' => $shipment->reference,
            ]);

            return $shipment;
        });
    }

    /** Open a manifest: a vehicle off the asset register, a driver, a date. */
    public function openManifest(FixedAsset $vehicle, ?User $driver, string $departsOn, User $by, ?string $notes = null): TripManifest
    {
        if (! $vehicle->isVehicle()) {
            throw new RuntimeException(
                "{$vehicle->name} has no vehicle record — only an asset with a plate can carry a manifest."
            );
        }

        if ($vehicle->isDisposed()) {
            throw new RuntimeException("{$vehicle->name} has been disposed of and cannot be dispatched.");
        }

        return TripManifest::create([
            'reference' => $this->nextReference('MAN', TripManifest::class),
            'fixed_asset_id' => $vehicle->id,
            'driver_id' => $driver?->id,
            'departs_on' => $departsOn,
            'status' => 'open',
            'notes' => $notes,
            'created_by' => $by->id,
        ]);
    }

    /**
     * Put a shipment aboard an open manifest.
     *
     * The rule that matters: a shipment may be aboard at most ONE open
     * manifest. The check and the attach run in one transaction; the pivot's
     * unique pair is the floor underneath for the same-manifest race.
     *
     * Loading deliberately does NOT write a VehicleTrip. Loading is warehouse
     * work — the van has not moved, and a trip is two odometer readings that
     * do not exist until it comes back. The journey is written once, when the
     * manifest closes with the readings in hand.
     */
    public function load(Shipment $shipment, TripManifest $manifest, User $by): Shipment
    {
        if (! $manifest->isOpen()) {
            throw new RuntimeException("{$manifest->reference} is {$manifest->statusLabel()} — only an open manifest can be loaded.");
        }

        if ($shipment->status !== 'booked') {
            throw new RuntimeException("{$shipment->reference} is {$shipment->statusLabel()} and cannot be loaded.");
        }

        return DB::transaction(function () use ($shipment, $manifest, $by) {
            $aboard = $shipment->manifests()
                ->where('trip_manifests.status', 'open')
                ->lockForUpdate()
                ->first();

            if ($aboard !== null) {
                throw new RuntimeException(
                    "{$shipment->reference} is already aboard {$aboard->reference}. Unload it there first."
                );
            }

            $manifest->shipments()->attach($shipment->id, ['company_id' => $shipment->company_id]);

            $shipment->forceFill(['status' => 'loaded'])->save();
            $this->record($shipment, 'loaded', 'Loaded for dispatch', $by);

            return $shipment->refresh();
        });
    }

    /**
     * The van leaves. The manifest turns `dispatched` and every shipment
     * aboard turns `in_transit` in the same transaction — a manifest that
     * left with half its cargo still reading "loaded" is a lie on the
     * tracking page.
     */
    public function dispatch(TripManifest $manifest, User $by): TripManifest
    {
        if (! $manifest->isOpen()) {
            throw new RuntimeException("{$manifest->reference} is already {$manifest->statusLabel()}.");
        }

        if ($manifest->shipments()->count() === 0) {
            throw new RuntimeException("{$manifest->reference} has nothing aboard — there is nothing to dispatch.");
        }

        return DB::transaction(function () use ($manifest, $by) {
            $manifest->forceFill(['status' => 'dispatched'])->save();

            foreach ($manifest->shipments()->where('shipments.status', 'loaded')->get() as $shipment) {
                $shipment->forceFill(['status' => 'in_transit'])->save();
                $this->record($shipment, 'in_transit', 'Departed '.$shipment->from_location, $by);
            }

            $manifest->emitDomainEvent('logistics.manifest.dispatched', [
                'manifest_id' => $manifest->id,
                'reference' => $manifest->reference,
            ]);

            return $manifest->refresh();
        });
    }

    /**
     * One shipment arrives.
     *
     * With `$withPod`, a proof-of-delivery paper is drafted and a signature
     * requested from the receiver through the existing signature flow —
     * DocumentSignatureRequests, the same mechanism every other paper in the
     * product signs with. The shipment stamps the link (`pod_document_id`);
     * it never grows a signature machine of its own.
     */
    public function deliver(Shipment $shipment, User $by, bool $withPod = false): Shipment
    {
        if ($shipment->status !== 'in_transit') {
            throw new RuntimeException("{$shipment->reference} is {$shipment->statusLabel()} — only cargo in transit can be delivered.");
        }

        return DB::transaction(function () use ($shipment, $by, $withPod) {
            $shipment->forceFill([
                'status' => 'delivered',
                'delivered_at' => now(),
            ]);

            if ($withPod) {
                $shipment->loadMissing('receiver');

                $paper = BusinessDocument::create([
                    'company_id' => $shipment->company_id,
                    'title' => 'Proof of delivery — '.$shipment->reference,
                    'recipient' => $shipment->receiver->name,
                    'kind' => 'document',
                    'body' => sprintf(
                        "Received in good order: %s (%s), %s to %s.\nShipment %s.",
                        $shipment->cargo_description,
                        $shipment->weight_kg !== null ? $shipment->weight_kg.' kg' : 'weight not recorded',
                        $shipment->from_location,
                        $shipment->to_location,
                        $shipment->reference,
                    ),
                    'status' => 'draft',
                    'owner_id' => $by->id,
                    'created_by' => $by->id,
                ]);

                app(DocumentSignatureRequests::class)->request($paper, [[
                    'name' => $shipment->receiver->name,
                    'email' => (string) ($shipment->receiver->email ?? ''),
                ]]);

                $shipment->forceFill(['pod_document_id' => $paper->id]);
            }

            $shipment->save();

            $this->record($shipment, 'delivered', 'Delivered at '.$shipment->to_location, $by);

            $shipment->emitDomainEvent('logistics.shipment.delivered', [
                'shipment_id' => $shipment->id,
                'reference' => $shipment->reference,
            ]);

            return $shipment->refresh();
        });
    }

    /** A booking that never travels. Only cargo not yet aboard can be struck. */
    public function cancel(Shipment $shipment, User $by, ?string $reason = null): Shipment
    {
        if ($shipment->status !== 'booked') {
            throw new RuntimeException("{$shipment->reference} is {$shipment->statusLabel()} and can no longer be cancelled here.");
        }

        return DB::transaction(function () use ($shipment, $by, $reason) {
            $shipment->forceFill(['status' => 'cancelled'])->save();
            $this->record($shipment, 'cancelled', $reason ?: 'Booking cancelled', $by);

            return $shipment->refresh();
        });
    }

    /**
     * The van is back. Everything aboard must have arrived (or been struck)
     * first — closing over undelivered cargo would strand it "in transit"
     * forever with the manifest gone.
     *
     * Given both odometer readings, the journey is written into the fleet's
     * own VehicleTrip log — the manifest linked to it, never keeping its own
     * mileage — so the van's servicing and consumption arithmetic see this
     * trip like any other.
     */
    public function close(TripManifest $manifest, User $by, ?int $startOdometer = null, ?int $endOdometer = null): TripManifest
    {
        if (! $manifest->isDispatched()) {
            throw new RuntimeException("{$manifest->reference} is {$manifest->statusLabel()} — only a dispatched manifest can be closed.");
        }

        $undelivered = $manifest->shipments()
            ->whereNotIn('shipments.status', ['delivered', 'cancelled'])
            ->count();

        if ($undelivered > 0) {
            throw new RuntimeException(
                "{$manifest->reference} still has {$undelivered} undelivered shipment(s) aboard. Deliver them before closing."
            );
        }

        return DB::transaction(function () use ($manifest, $by, $startOdometer, $endOdometer) {
            $fill = ['status' => 'closed'];

            if ($startOdometer !== null && $endOdometer !== null) {
                if ($endOdometer < $startOdometer) {
                    throw new RuntimeException('The closing odometer reading cannot be below the departing one.');
                }

                $trip = VehicleTrip::create([
                    'company_id' => $manifest->company_id,
                    'fixed_asset_id' => $manifest->fixed_asset_id,
                    'driver_id' => $manifest->driver_id,
                    'trip_date' => $manifest->departs_on,
                    'start_odometer' => $startOdometer,
                    'end_odometer' => $endOdometer,
                    'purpose' => 'Manifest '.$manifest->reference,
                    'created_by' => $by->id,
                ]);

                $fill['vehicle_trip_id'] = $trip->id;
            }

            $manifest->forceFill($fill)->save();

            return $manifest->refresh();
        });
    }

    /**
     * Draft the freight invoice — an ordinary sales invoice in `documents`,
     * with the same VAT arithmetic as every other invoice, billed to the
     * sender. The shipment keeps `document_id`, a link and nothing more.
     *
     * Draft, not issued: freight gets renegotiated at the counter, and a
     * module that issued automatically would send arguments to customers.
     */
    public function draftInvoice(Shipment $shipment, User $by): Document
    {
        if ($shipment->isInvoiced()) {
            throw new RuntimeException(
                "{$shipment->reference} is already on an invoice. Credit that one rather than raising a second."
            );
        }

        if ($shipment->freight_amount === null || (float) $shipment->freight_amount <= 0) {
            throw new RuntimeException("{$shipment->reference} has no freight amount to invoice.");
        }

        if ($shipment->status === 'cancelled') {
            throw new RuntimeException("{$shipment->reference} was cancelled — there is nothing to charge for.");
        }

        return DB::transaction(function () use ($shipment, $by) {
            $company = $shipment->company;

            $lines = [[
                'description' => sprintf(
                    'Freight — %s, %s to %s (%s)',
                    $shipment->cargo_description,
                    $shipment->from_location,
                    $shipment->to_location,
                    $shipment->reference,
                ),
                'quantity' => 1.0,
                'unit' => 'shipment',
                'unit_price' => (float) $shipment->freight_amount,
            ]];

            $vat = Vat::forCompany($company, $lines);

            $invoice = Document::create([
                'company_id' => $shipment->company_id,
                'type' => DocumentType::Invoice,
                'contact_id' => $shipment->sender_id,
                'status' => DocumentStatus::Draft,
                'issue_date' => now()->toDateString(),
                'due_date' => now()->addDays(30)->toDateString(),
                'currency' => $company->currency,
                'subtotal' => $vat['subtotal'],
                'discount_total' => $vat['discount_total'],
                'tax_total' => $vat['tax_total'],
                'total' => $vat['total'],
                'amount_paid' => 0,
                'balance' => $vat['total'],
                'created_by' => $by->id,
            ]);

            foreach ($lines as $index => $line) {
                DocumentLine::create([
                    'document_id' => $invoice->id,
                    'description' => $line['description'],
                    'quantity' => $line['quantity'],
                    'unit' => $line['unit'],
                    'unit_price' => $line['unit_price'],
                    'tax_amount' => $vat['lines'][$index]['tax'] ?? 0.0,
                    'line_total' => $vat['lines'][$index]['net'] ?? 0.0,
                    'sort_order' => $index,
                ]);
            }

            $shipment->forceFill(['document_id' => $invoice->id])->save();

            $shipment->emitDomainEvent('logistics.shipment.invoiced', [
                'shipment_id' => $shipment->id,
                'document_id' => $invoice->id,
                'total' => (float) $invoice->total,
            ]);

            return $invoice->refresh();
        });
    }

    /** One event row per turn of the dial — the story the tracking page tells. */
    protected function record(Shipment $shipment, string $status, ?string $note, User $by): void
    {
        $shipment->events()->create([
            'company_id' => $shipment->company_id,
            'status' => $status,
            'note' => $note,
            'happened_at' => now(),
            'created_by' => $by->id,
        ]);
    }

    /** "SHP-260915-K4TQ" — dated, human-readable, unique per company by index. */
    protected function nextReference(string $prefix, string $model = Shipment::class): string
    {
        return UniqueId::make(
            fn () => $prefix.'-'.now()->format('ymd').'-'.strtoupper(Str::random(4)),
            fn (string $ref) => $model::query()->where('reference', $ref)->exists(),
        );
    }
}
