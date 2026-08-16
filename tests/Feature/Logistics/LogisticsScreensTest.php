<?php

namespace Tests\Feature\Logistics;

use App\Livewire\Logistics\Index;
use App\Livewire\Logistics\Show;
use Livewire\Livewire;

class LogisticsScreensTest extends LogisticsTestCase
{
    public function test_the_dispatch_board_renders_its_three_columns(): void
    {
        $waiting = $this->shipment();
        $manifest = $this->manifest();

        $aboard = $this->shipment(['cargo_description' => 'Bags of rice']);
        $this->dispatcher()->load($aboard, $manifest, $this->owner);

        Livewire::actingAs($this->owner)
            ->test(Index::class)
            ->assertOk()
            ->assertSee($waiting->reference)
            ->assertSee($manifest->reference)
            ->assertSee('1 aboard');
    }

    public function test_the_board_can_book_a_shipment(): void
    {
        Livewire::actingAs($this->owner)
            ->test(Index::class)
            ->set('senderId', $this->sender->id)
            ->set('receiverId', $this->receiver->id)
            ->set('cargo', 'Palettes of soap')
            ->set('fromLocation', 'Douala')
            ->set('toLocation', 'Yaounde')
            ->set('freightAmount', '150000')
            ->call('book')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('shipments', [
            'company_id' => $this->company->id,
            'cargo_description' => 'Palettes of soap',
            'status' => 'booked',
        ]);
    }

    public function test_the_board_surfaces_a_service_refusal_instead_of_swallowing_it(): void
    {
        $shipment = $this->shipment();
        $first = $this->manifest();
        $second = $this->manifest();
        $this->dispatcher()->load($shipment, $first, $this->owner);

        Livewire::actingAs($this->owner)
            ->test(Index::class)
            ->set('loadShipmentId', $shipment->id)
            ->call('load', $second->id)
            ->assertHasErrors('loadShipmentId');
    }

    public function test_the_shipment_screen_shows_history_pod_and_invoice_links(): void
    {
        $shipment = $this->shipment();
        $manifest = $this->manifest();
        $this->dispatcher()->load($shipment, $manifest, $this->owner);
        $this->dispatcher()->dispatch($manifest, $this->owner);
        $shipment = $this->dispatcher()->deliver($shipment->fresh(), $this->owner, withPod: true);
        $this->dispatcher()->draftInvoice($shipment->fresh(), $this->owner);

        Livewire::actingAs($this->owner)
            ->test(Show::class, ['shipment' => $shipment->fresh()])
            ->assertOk()
            ->assertSee('Delivered')
            ->assertSee('Proof of delivery')
            ->assertSee('pending')
            ->assertSee('Freight invoice')
            ->assertSee($shipment->tracking_token);
    }

    public function test_the_shipment_screen_can_deliver_with_a_pod(): void
    {
        $shipment = $this->shipment();
        $manifest = $this->manifest();
        $this->dispatcher()->load($shipment, $manifest, $this->owner);
        $this->dispatcher()->dispatch($manifest, $this->owner);

        Livewire::actingAs($this->owner)
            ->test(Show::class, ['shipment' => $shipment->fresh()])
            ->set('withPod', true)
            ->call('deliver')
            ->assertHasNoErrors();

        $fresh = $shipment->fresh();
        $this->assertSame('delivered', $fresh->status);
        $this->assertNotNull($fresh->pod_document_id);
    }
}
