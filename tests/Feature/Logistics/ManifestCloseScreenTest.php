<?php

namespace Tests\Feature\Logistics;

use App\Livewire\Logistics\Index;
use App\Models\VehicleTrip;
use Livewire\Livewire;

/**
 * The close button on the dispatch board: the van comes back, the odometer
 * readings are typed once, and the journey lands in the fleet's own trip log.
 */
class ManifestCloseScreenTest extends LogisticsTestCase
{
    public function test_a_dispatched_manifest_closes_from_the_board_with_odometer_readings(): void
    {
        $shipment = $this->shipment();
        $manifest = $this->manifest();

        $this->dispatcher()->load($shipment, $manifest, $this->owner);
        $this->dispatcher()->dispatch($manifest->fresh(), $this->owner);
        $this->dispatcher()->deliver($shipment->fresh(), $this->owner);

        Livewire::actingAs($this->owner)
            ->test(Index::class)
            ->call('startClosing', $manifest->id)
            ->set('startOdometer', '120000')
            ->set('endOdometer', '120480')
            ->call('closeManifest', $manifest->id)
            ->assertHasNoErrors();

        $manifest->refresh();
        $this->assertSame('closed', $manifest->status);
        $this->assertNotNull($manifest->vehicle_trip_id);

        $trip = VehicleTrip::findOrFail($manifest->vehicle_trip_id);
        $this->assertSame(120000, (int) $trip->start_odometer);
        $this->assertSame(120480, (int) $trip->end_odometer);
    }

    public function test_a_backwards_odometer_reading_shows_the_services_refusal(): void
    {
        $shipment = $this->shipment();
        $manifest = $this->manifest();

        $this->dispatcher()->load($shipment, $manifest, $this->owner);
        $this->dispatcher()->dispatch($manifest->fresh(), $this->owner);
        $this->dispatcher()->deliver($shipment->fresh(), $this->owner);

        Livewire::actingAs($this->owner)
            ->test(Index::class)
            ->call('startClosing', $manifest->id)
            ->set('startOdometer', '120480')
            ->set('endOdometer', '120000')
            ->call('closeManifest', $manifest->id)
            ->assertHasErrors('endOdometer');

        $this->assertSame('dispatched', $manifest->fresh()->status);
    }

    public function test_a_manifest_with_undelivered_cargo_refuses_to_close(): void
    {
        $shipment = $this->shipment();
        $manifest = $this->manifest();

        $this->dispatcher()->load($shipment, $manifest, $this->owner);
        $this->dispatcher()->dispatch($manifest->fresh(), $this->owner);

        Livewire::actingAs($this->owner)
            ->test(Index::class)
            ->call('closeManifest', $manifest->id)
            ->assertHasErrors('endOdometer');

        $this->assertSame('dispatched', $manifest->fresh()->status);
    }
}
