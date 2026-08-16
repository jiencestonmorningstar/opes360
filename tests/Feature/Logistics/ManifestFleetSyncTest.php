<?php

namespace Tests\Feature\Logistics;

use App\Models\VehicleTrip;
use App\Services\Fleet\VehicleUsage;

/**
 * The fleet-sync check: closing a manifest with odometer readings writes ONE
 * VehicleTrip, and the fleet's own odometer arithmetic — the number
 * distance-based servicing schedules from — sees that trip like any other.
 */
class ManifestFleetSyncTest extends LogisticsTestCase
{
    public function test_the_fleet_odometer_includes_manifest_trips(): void
    {
        $shipment = $this->shipment();
        $manifest = $this->manifest();
        $this->dispatcher()->load($shipment, $manifest, $this->owner);
        $this->dispatcher()->dispatch($manifest, $this->owner);
        $this->dispatcher()->deliver($shipment->refresh(), $this->owner);

        $usage = app(VehicleUsage::class);
        $this->assertNull($usage->odometer($this->truck), 'An unmeasured van reads nothing, not zero.');

        $closed = $this->dispatcher()->close($manifest->refresh(), $this->owner, 84_200, 84_450);

        // One trip, linked from the manifest, in the fleet's own log.
        $trip = VehicleTrip::query()->where('fixed_asset_id', $this->truck->id)->get();
        $this->assertCount(1, $trip);
        $this->assertSame($trip->first()->id, $closed->vehicle_trip_id);
        $this->assertSame('Manifest '.$manifest->reference, $trip->first()->purpose);

        // The reading distance-based servicing schedules from is the
        // manifest's closing reading — dispatch journeys and fleet journeys
        // are the same history.
        $this->assertSame(84_450, $usage->odometer($this->truck));
        $this->assertSame(250, $trip->first()->distance());
    }
}
