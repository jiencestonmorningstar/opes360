<?php

namespace Tests\Feature\Api;

use App\Models\StockMovement;

/**
 * Outbound fulfilment over the token API: the promise, the hold, the goods.
 */
class SalesOrderApiTest extends Wave4ApiTestCase
{
    public function test_an_order_is_drafted_with_its_lines(): void
    {
        $contact = $this->makeContact();
        $item = $this->makeStockedItem('Ciment 50kg', onHand: 100, price: 6500);

        $this->postJson('/api/v1/orders', [
            'contact_id' => $contact->id,
            'lines' => [['item_id' => $item->id, 'quantity' => 10]],
        ])->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.lines.0.quantity_ordered', 10)
            // The catalogue price rode in because none was named.
            ->assertJsonPath('data.lines.0.unit_price', 6500);
    }

    public function test_confirming_reserves_what_exists_and_names_the_shortage(): void
    {
        $contact = $this->makeContact();
        $item = $this->makeStockedItem('Tôle ondulée', onHand: 6);

        $id = $this->postJson('/api/v1/orders', [
            'contact_id' => $contact->id,
            'lines' => [['item_id' => $item->id, 'quantity' => 10]],
        ])->json('data.id');

        $this->postJson("/api/v1/orders/{$id}/confirm")
            ->assertOk()
            ->assertJsonPath('data.order.status', 'confirmed')
            ->assertJsonPath('data.order.lines.0.quantity_reserved', 6)
            ->assertJsonPath('data.order.lines.0.quantity_backordered', 4)
            // The shortage is a figure a person reads, never a quiet truncation.
            ->assertJsonPath('data.shortages.0.short', 4);

        // The draft check holds on a retry.
        $this->postJson("/api/v1/orders/{$id}/confirm")->assertStatus(422);
    }

    public function test_delivering_writes_the_note_and_the_movements(): void
    {
        $contact = $this->makeContact();
        $item = $this->makeStockedItem('Fer à béton', onHand: 20);

        $id = $this->postJson('/api/v1/orders', [
            'contact_id' => $contact->id,
            'lines' => [['item_id' => $item->id, 'quantity' => 5]],
        ])->json('data.id');

        $this->postJson("/api/v1/orders/{$id}/confirm")->assertOk();

        $this->postJson("/api/v1/orders/{$id}/deliver")
            ->assertCreated()
            ->assertJsonPath('data.lines.0.quantity', 5);

        // The goods left through the ordinary ledger, reason 'sale'.
        $this->assertEquals(
            -5.0,
            (float) StockMovement::query()->where('item_id', $item->id)->where('reason', 'sale')->sum('quantity')
        );

        $this->getJson("/api/v1/orders/{$id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'delivered');
    }

    public function test_a_draft_cannot_be_delivered(): void
    {
        $contact = $this->makeContact();
        $item = $this->makeStockedItem('Peinture', onHand: 10);

        $id = $this->postJson('/api/v1/orders', [
            'contact_id' => $contact->id,
            'lines' => [['item_id' => $item->id, 'quantity' => 2]],
        ])->json('data.id');

        $this->postJson("/api/v1/orders/{$id}/deliver")
            ->assertStatus(422)
            ->assertJsonStructure(['message']);
    }
}
