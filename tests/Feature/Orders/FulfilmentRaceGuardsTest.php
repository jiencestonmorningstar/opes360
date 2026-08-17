<?php

namespace Tests\Feature\Orders;

use App\Models\SalesOrder;
use App\Models\StockMovement;
use App\Models\StockReservation;
use App\Services\Orders\Fulfilment;
use App\Services\Stock\StockReservations;
use RuntimeException;

/**
 * Race guards around confirming and delivering orders.
 *
 * A genuine two-connection race cannot run inside one sqlite test
 * transaction, so each test replays it single-threaded with a stale
 * in-memory model — the state the losing request holds — and asserts the
 * status re-read under lock inside the transaction refuses it. The
 * availability race itself (two confirms summing the same snapshot) is
 * guarded by the item-row lock in StockReservations::reserve, which sqlite
 * cannot demonstrate; the sequential over-reserve refusal is asserted
 * instead.
 */
class FulfilmentRaceGuardsTest extends OrdersTestCase
{
    protected function fulfilment(): Fulfilment
    {
        return app(Fulfilment::class);
    }

    public function test_confirming_with_a_stale_model_cannot_reserve_twice(): void
    {
        $this->stockUp($this->cement, 10, 5000);
        $order = $this->draftOrder([['item_id' => $this->cement->id, 'quantity' => 10]]);

        // The stale draft a second browser tab still holds.
        $stale = SalesOrder::query()->findOrFail($order->id);

        $this->fulfilment()->confirm($order, $this->owner);

        try {
            $this->fulfilment()->confirm($stale, $this->owner);
            $this->fail('Confirming an already-confirmed order must be refused.');
        } catch (RuntimeException) {
            // Expected.
        }

        $this->assertSame(
            1,
            StockReservation::query()->where('item_id', $this->cement->id)->count(),
            'The stock must be promised exactly once.'
        );
    }

    public function test_delivering_with_a_stale_model_cannot_ship_twice(): void
    {
        $this->stockUp($this->cement, 10, 5000);
        $order = $this->draftOrder([['item_id' => $this->cement->id, 'quantity' => 10]]);
        $this->fulfilment()->confirm($order, $this->owner);

        // The stale confirmed order a concurrent deliver would hold.
        $stale = SalesOrder::query()->findOrFail($order->id);

        $this->fulfilment()->deliver($order, actor: $this->owner);

        try {
            $this->fulfilment()->deliver($stale, actor: $this->owner);
            $this->fail('Delivering a delivered order must be refused.');
        } catch (RuntimeException) {
            // Expected.
        }

        $shipped = StockMovement::query()
            ->where('item_id', $this->cement->id)
            ->where('reason', 'sale')
            ->count();

        $this->assertSame(1, $shipped, 'The goods must leave the shelf exactly once.');
        $this->assertSame(0.0, (float) $order->fresh()->lines()->sum('quantity_reserved'));
    }

    public function test_reserving_beyond_what_is_available_is_refused(): void
    {
        $this->stockUp($this->cement, 10, 5000);

        app(StockReservations::class)->reserve($this->cement, 8, actor: $this->owner);

        $this->expectException(RuntimeException::class);
        app(StockReservations::class)->reserve($this->cement, 8, actor: $this->owner);
    }
}
