<?php

namespace Tests\Feature\Logistics;

use App\Events\DomainEvent;
use App\Livewire\Logistics\Show;
use App\Models\Shipment;
use App\Support\DomainEvents;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use RuntimeException;

/**
 * The exception path: a delivery attempt that failed, loudly, with a reason —
 * and the two ways out of it: back on the road, or home to the sender.
 */
class DeliveryExceptionTest extends LogisticsTestCase
{
    /** Book, load, dispatch — cargo on the road, ready to fail. */
    protected function inTransit(): Shipment
    {
        $shipment = $this->shipment();
        $manifest = $this->manifest();
        $this->dispatcher()->load($shipment, $manifest, $this->owner);
        $this->dispatcher()->dispatch($manifest, $this->owner);

        return $shipment->refresh();
    }

    public function test_a_failed_attempt_sets_exception_and_records_the_reason(): void
    {
        $shipment = $this->inTransit();

        $this->dispatcher()->failDelivery($shipment, $this->owner, 'Absent receiver');

        $shipment->refresh();
        $this->assertSame('exception', $shipment->status);
        $this->assertTrue($shipment->inException());
        $this->assertFalse($shipment->isSettled());

        $event = $shipment->events()->where('status', 'exception')->first();
        $this->assertNotNull($event);
        $this->assertStringContainsString('Absent receiver', (string) $event->note);
    }

    public function test_a_failed_attempt_requires_a_reason(): void
    {
        $shipment = $this->inTransit();

        $this->expectException(RuntimeException::class);
        $this->dispatcher()->failDelivery($shipment, $this->owner, '   ');
    }

    public function test_only_cargo_in_transit_can_fail(): void
    {
        $shipment = $this->shipment(); // still booked

        $this->expectException(RuntimeException::class);
        $this->dispatcher()->failDelivery($shipment, $this->owner, 'Absent receiver');
    }

    public function test_the_failed_event_carries_the_reason_and_the_tracking_url(): void
    {
        $shipment = $this->inTransit();

        Event::fake([DomainEvent::class]);

        $this->dispatcher()->failDelivery($shipment, $this->owner, 'Cargo refused at the door');

        Event::assertDispatched(DomainEvent::class, function (DomainEvent $e) use ($shipment) {
            return $e->name === 'logistics.shipment.failed'
                && $e->context['reason'] === 'Cargo refused at the door'
                && $e->context['tracking_url'] === $shipment->trackingUrl();
        });
    }

    public function test_booked_and_delivered_events_carry_the_tracking_url(): void
    {
        Event::fake([DomainEvent::class]);

        $shipment = $this->shipment();

        Event::assertDispatched(DomainEvent::class, fn (DomainEvent $e) => $e->name === 'logistics.shipment.booked'
            && $e->context['tracking_url'] === $shipment->trackingUrl());
    }

    public function test_retry_puts_the_shipment_back_in_transit(): void
    {
        $shipment = $this->inTransit();
        $this->dispatcher()->failDelivery($shipment, $this->owner, 'Absent receiver');

        $this->dispatcher()->retryDelivery($shipment->refresh(), $this->owner);

        $this->assertSame('in_transit', $shipment->refresh()->status);

        // And it can now be delivered like any other cargo.
        $this->dispatcher()->deliver($shipment, $this->owner);
        $this->assertSame('delivered', $shipment->refresh()->status);
    }

    public function test_return_to_sender_settles_the_shipment(): void
    {
        $shipment = $this->inTransit();
        $this->dispatcher()->failDelivery($shipment, $this->owner, 'Cargo refused');

        $this->dispatcher()->returnToSender($shipment->refresh(), $this->owner);

        $shipment->refresh();
        $this->assertSame('returned', $shipment->status);
        $this->assertTrue($shipment->isSettled());
    }

    public function test_retry_and_return_refuse_anything_not_in_exception(): void
    {
        $shipment = $this->inTransit();

        $this->expectException(RuntimeException::class);
        $this->dispatcher()->retryDelivery($shipment, $this->owner);
    }

    /**
     * The van came back with the excepted cargo aboard: the manifest can
     * close over it. The depot is exactly where retry-or-return is decided,
     * and the truck's odometer readings must not wait on that argument.
     */
    public function test_a_manifest_closes_over_an_excepted_shipment(): void
    {
        $shipment = $this->shipment();
        $other = $this->shipment();
        $manifest = $this->manifest();

        $this->dispatcher()->load($shipment, $manifest, $this->owner);
        $this->dispatcher()->load($other, $manifest, $this->owner);
        $this->dispatcher()->dispatch($manifest, $this->owner);

        $this->dispatcher()->deliver($other->refresh(), $this->owner);
        $this->dispatcher()->failDelivery($shipment->refresh(), $this->owner, 'Absent receiver');

        $closed = $this->dispatcher()->close($manifest->refresh(), $this->owner);

        $this->assertSame('closed', $closed->status);
        // The exception survives the closed manifest — still loud on the board.
        $this->assertSame('exception', $shipment->refresh()->status);
    }

    public function test_the_board_shows_exceptions_and_the_screen_offers_the_ways_out(): void
    {
        $shipment = $this->inTransit();
        $this->dispatcher()->failDelivery($shipment, $this->owner, 'Absent receiver');

        $this->actingAs($this->owner)
            ->get('/logistics')
            ->assertOk()
            ->assertSee('Delivery exceptions')
            ->assertSee($shipment->reference);

        Livewire::actingAs($this->owner)
            ->test(Show::class, ['shipment' => $shipment->refresh()])
            ->call('retry')
            ->assertHasNoErrors();

        $this->assertSame('in_transit', $shipment->refresh()->status);
    }

    public function test_the_screen_requires_a_reason_before_failing(): void
    {
        $shipment = $this->inTransit();

        Livewire::actingAs($this->owner)
            ->test(Show::class, ['shipment' => $shipment])
            ->set('failing', true)
            ->set('failReason', '')
            ->call('fail')
            ->assertHasErrors(['failReason']);

        $this->assertSame('in_transit', $shipment->refresh()->status);
    }

    public function test_the_logistics_events_are_in_the_catalogue(): void
    {
        foreach ([
            'logistics.shipment.booked',
            'logistics.shipment.delivered',
            'logistics.shipment.failed',
            'logistics.shipment.invoiced',
            'logistics.manifest.dispatched',
        ] as $event) {
            $this->assertTrue(
                DomainEvents::exists($event),
                "{$event} should be selectable by automation and notification rules.",
            );
        }
    }
}
