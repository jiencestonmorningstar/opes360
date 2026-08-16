<?php

namespace Tests\Feature\Logistics;

use App\Models\BusinessDocument;
use App\Models\VehicleTrip;
use App\Services\Documents\DocumentSignatureRequests;
use RuntimeException;

class DispatchTest extends LogisticsTestCase
{
    public function test_booking_creates_a_tracked_shipment_with_its_first_event(): void
    {
        $shipment = $this->shipment();

        $this->assertSame('booked', $shipment->status);
        $this->assertNotNull($shipment->tracking_token);
        $this->assertStringStartsWith('SHP-', $shipment->reference);
        $this->assertSame(['booked'], $shipment->events->pluck('status')->all());
    }

    public function test_a_shipment_cannot_board_two_open_manifests(): void
    {
        $shipment = $this->shipment();
        $first = $this->manifest();
        $second = $this->manifest();

        $this->dispatcher()->load($shipment, $first, $this->owner);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already aboard');

        $this->dispatcher()->load($shipment->fresh(), $second, $this->owner);
    }

    public function test_only_a_booked_shipment_can_be_loaded_and_only_onto_an_open_manifest(): void
    {
        $shipment = $this->shipment();
        $manifest = $this->manifest();

        $this->dispatcher()->load($shipment, $manifest, $this->owner);
        $this->assertSame('loaded', $shipment->fresh()->status);

        // Loaded is not booked — it cannot be loaded a second time anywhere.
        $this->expectException(RuntimeException::class);
        $this->dispatcher()->load($shipment->fresh(), $this->manifest(), $this->owner);
    }

    public function test_dispatch_flips_every_shipment_aboard_to_in_transit(): void
    {
        $a = $this->shipment();
        $b = $this->shipment(['cargo_description' => '12 drums of oil']);
        $manifest = $this->manifest();

        $this->dispatcher()->load($a, $manifest, $this->owner);
        $this->dispatcher()->load($b, $manifest, $this->owner);

        $manifest = $this->dispatcher()->dispatch($manifest, $this->owner);

        $this->assertSame('dispatched', $manifest->status);
        $this->assertSame('in_transit', $a->fresh()->status);
        $this->assertSame('in_transit', $b->fresh()->status);
        $this->assertContains('in_transit', $a->fresh()->events->pluck('status')->all());
    }

    public function test_an_empty_manifest_cannot_be_dispatched(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('nothing aboard');

        $this->dispatcher()->dispatch($this->manifest(), $this->owner);
    }

    public function test_delivery_stamps_the_status_and_requests_the_pod_signature(): void
    {
        $shipment = $this->shipment();
        $manifest = $this->manifest();
        $this->dispatcher()->load($shipment, $manifest, $this->owner);
        $this->dispatcher()->dispatch($manifest, $this->owner);

        $shipment = $this->dispatcher()->deliver($shipment->fresh(), $this->owner, withPod: true);

        $this->assertSame('delivered', $shipment->status);
        $this->assertNotNull($shipment->delivered_at);

        // The POD is a BusinessDocument in the papers module, put through the
        // EXISTING signature flow — the receiver is the pending signer.
        $paper = BusinessDocument::find($shipment->pod_document_id);
        $this->assertNotNull($paper);

        $signature = $paper->signatures()->first();
        $this->assertSame('pending', $signature->status);
        $this->assertSame($this->receiver->name, $signature->signer_name);

        // Signing goes through the same service every other paper signs with.
        app(DocumentSignatureRequests::class)->sign($signature);
        $this->assertSame('signed', $signature->fresh()->status);
    }

    public function test_delivery_without_pod_leaves_no_paper_behind(): void
    {
        $shipment = $this->shipment();
        $manifest = $this->manifest();
        $this->dispatcher()->load($shipment, $manifest, $this->owner);
        $this->dispatcher()->dispatch($manifest, $this->owner);

        $shipment = $this->dispatcher()->deliver($shipment->fresh(), $this->owner, withPod: false);

        $this->assertSame('delivered', $shipment->status);
        $this->assertNull($shipment->pod_document_id);
    }

    public function test_closing_writes_the_journey_into_the_fleet_log(): void
    {
        $shipment = $this->shipment();
        $manifest = $this->manifest();
        $this->dispatcher()->load($shipment, $manifest, $this->owner);
        $this->dispatcher()->dispatch($manifest, $this->owner);
        $this->dispatcher()->deliver($shipment->fresh(), $this->owner);

        $manifest = $this->dispatcher()->close($manifest->fresh(), $this->owner, 120_400, 120_690);

        $this->assertSame('closed', $manifest->status);

        // The journey is a VehicleTrip in the fleet's own log — the truck's
        // mileage history and the dispatch history are the same history.
        $trip = VehicleTrip::find($manifest->vehicle_trip_id);
        $this->assertNotNull($trip);
        $this->assertSame($this->truck->id, $trip->fixed_asset_id);
        $this->assertSame(290, $trip->distance());
    }

    public function test_a_manifest_cannot_close_over_undelivered_cargo(): void
    {
        $shipment = $this->shipment();
        $manifest = $this->manifest();
        $this->dispatcher()->load($shipment, $manifest, $this->owner);
        $this->dispatcher()->dispatch($manifest, $this->owner);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('undelivered');

        $this->dispatcher()->close($manifest->fresh(), $this->owner);
    }

    public function test_a_delivered_shipment_may_board_a_manifest_no_more_but_a_closed_manifest_frees_nothing(): void
    {
        // The one-open-manifest rule bites on OPEN manifests only: history on
        // a closed manifest does not block anything (there is nothing to
        // block — a booked shipment is loadable, a delivered one is not).
        $shipment = $this->shipment();
        $first = $this->manifest();
        $this->dispatcher()->load($shipment, $first, $this->owner);
        $this->dispatcher()->dispatch($first, $this->owner);
        $this->dispatcher()->deliver($shipment->fresh(), $this->owner);
        $this->dispatcher()->close($first->fresh(), $this->owner);

        $this->expectException(RuntimeException::class);
        $this->dispatcher()->load($shipment->fresh(), $this->manifest(), $this->owner);
    }

    public function test_only_a_booked_shipment_can_be_cancelled(): void
    {
        $shipment = $this->shipment();
        $this->dispatcher()->cancel($shipment, $this->owner);
        $this->assertSame('cancelled', $shipment->fresh()->status);

        $travelling = $this->shipment();
        $manifest = $this->manifest();
        $this->dispatcher()->load($travelling, $manifest, $this->owner);

        $this->expectException(RuntimeException::class);
        $this->dispatcher()->cancel($travelling->fresh(), $this->owner);
    }
}
