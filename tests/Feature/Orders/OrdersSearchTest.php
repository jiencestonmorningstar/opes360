<?php

namespace Tests\Feature\Orders;

use App\Models\DeliveryNote;
use App\Models\Scopes\CompanyScope;
use App\Models\SearchEntry;
use App\Search\GlobalSearch;
use App\Services\Orders\Fulfilment;

/**
 * Orders in the one search box: a sales order and its delivery note are
 * findable by number under the orders.view ability, and a voided note
 * drops out of the index the way a void Document does.
 */
class OrdersSearchTest extends OrdersTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        GlobalSearch::observe();
    }

    protected function entryFor(object $model): ?SearchEntry
    {
        return SearchEntry::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('searchable_type', $model->getMorphClass())
            ->where('searchable_id', (string) $model->getKey())
            ->first();
    }

    public function test_orders_and_delivery_notes_are_indexed_under_orders_view(): void
    {
        $this->stockUp($this->cement, 10, 5000);

        $order = $this->draftOrder([['item_id' => $this->cement->id, 'quantity' => 5]]);
        app(Fulfilment::class)->confirm($order, $this->owner);
        $note = app(Fulfilment::class)->deliver($order, actor: $this->owner);

        $orderEntry = $this->entryFor($order->fresh());
        $this->assertNotNull($orderEntry);
        $this->assertSame($order->number, $orderEntry->title);
        $this->assertSame('orders.view', $orderEntry->ability);
        $this->assertStringContainsString('Chantier Mbarga', (string) $orderEntry->subtitle);

        $noteEntry = $this->entryFor($note->fresh());
        $this->assertNotNull($noteEntry);
        $this->assertSame($note->number, $noteEntry->title);
        $this->assertSame('orders.view', $noteEntry->ability);
    }

    public function test_searching_finds_the_order_by_number(): void
    {
        $this->stockUp($this->cement, 10, 5000);
        $order = $this->draftOrder([['item_id' => $this->cement->id, 'quantity' => 5]]);

        $results = GlobalSearch::query($this->owner, $this->company, $order->number);

        $this->assertArrayHasKey('Sales orders', $results);
        $this->assertSame($order->number, $results['Sales orders'][0]['title']);
    }

    public function test_a_voided_delivery_note_drops_out_of_the_index(): void
    {
        $this->stockUp($this->cement, 10, 5000);
        $order = $this->draftOrder([['item_id' => $this->cement->id, 'quantity' => 5]]);
        app(Fulfilment::class)->confirm($order, $this->owner);
        $note = app(Fulfilment::class)->deliver($order, actor: $this->owner);

        $this->assertNotNull($this->entryFor($note->fresh()));

        $note->fresh()->forceFill(['status' => DeliveryNote::STATUS_VOID])->save();

        $this->assertNull($this->entryFor($note->fresh()));
    }
}
