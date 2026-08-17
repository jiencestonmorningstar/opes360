<?php

namespace Tests\Feature\Api;

/**
 * Shipments over the token API: booking, and the event history behind the
 * tracking page.
 */
class LogisticsApiTest extends Wave4ApiTestCase
{
    public function test_a_booking_mints_the_tracking_link_immediately(): void
    {
        $sender = $this->makeContact('Quincaillerie Centrale');
        $receiver = $this->makeContact('Depot Garoua');

        $response = $this->postJson('/api/v1/logistics/shipments', [
            'sender_id' => $sender->id,
            'receiver_id' => $receiver->id,
            'cargo_description' => '40 sacs de ciment',
            'from_location' => 'Douala',
            'to_location' => 'Garoua',
            'freight_amount' => 180000,
        ])->assertCreated()
            ->assertJsonPath('data.status', 'booked');

        // The customer gets their link at the counter, not when the van leaves.
        $this->assertStringContainsString('/track/', $response->json('data.tracking_url'));

        $id = $response->json('data.id');

        $this->getJson("/api/v1/logistics/shipments/{$id}")
            ->assertOk()
            ->assertJsonPath('data.events.0.status', 'booked');

        $this->getJson("/api/v1/logistics/shipments/{$id}/events")
            ->assertOk()
            ->assertJsonPath('data.0.status', 'booked');
    }

    public function test_a_booking_needs_parties_from_the_customer_book(): void
    {
        $this->postJson('/api/v1/logistics/shipments', [
            'sender_id' => '01j00000000000000000000000',
            'receiver_id' => '01j00000000000000000000000',
            'cargo_description' => 'Mystery cargo',
            'from_location' => 'Douala',
            'to_location' => 'Yaoundé',
        ])->assertStatus(422)->assertJsonStructure(['message']);
    }
}
