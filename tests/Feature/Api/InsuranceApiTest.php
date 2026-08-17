<?php

namespace Tests\Feature\Api;

/**
 * The broking vertical over the token API: placing cover, notifying a loss.
 */
class InsuranceApiTest extends Wave4ApiTestCase
{
    public function test_a_policy_is_placed_as_a_draft(): void
    {
        $holder = $this->makeContact('Transports Mbarga');

        $this->postJson('/api/v1/insurance/policies', [
            'holder_contact_id' => $holder->id,
            'product_line' => 'motor',
            'premium' => 450000,
            'covers_from' => '2026-01-01',
            'covers_to' => '2026-12-31',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.product_line', 'motor')
            ->assertJsonPath('data.premium', 450000);

        $this->getJson('/api/v1/insurance/policies?product_line=motor')
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_auto_renewal_without_a_notice_period_is_refused(): void
    {
        $holder = $this->makeContact();

        // The silence trap: nobody could be told in time to rebroke it.
        $this->postJson('/api/v1/insurance/policies', [
            'holder_contact_id' => $holder->id,
            'covers_from' => '2026-01-01',
            'covers_to' => '2026-12-31',
            'renewal_type' => 'auto',
        ])->assertStatus(422)->assertJsonStructure(['message']);
    }

    public function test_a_claim_inside_cover_opens_and_one_outside_is_refused(): void
    {
        $holder = $this->makeContact();

        $policyId = $this->postJson('/api/v1/insurance/policies', [
            'holder_contact_id' => $holder->id,
            'covers_from' => '2026-01-01',
            'covers_to' => '2026-12-31',
        ])->json('data.id');

        $claimId = $this->postJson("/api/v1/insurance/policies/{$policyId}/claims", [
            'incident_on' => '2026-06-15',
            'description' => 'Rear-end collision at Carrefour Nlongkak.',
            'claimed_amount' => 250000,
        ])->assertCreated()
            ->assertJsonPath('data.status', 'fnol')
            ->json('data.id');

        $this->getJson("/api/v1/insurance/claims/{$claimId}")
            ->assertOk()
            ->assertJsonPath('data.insurance_policy_id', $policyId);

        // An incident before the cover started is not this policy's loss.
        $this->postJson("/api/v1/insurance/policies/{$policyId}/claims", [
            'incident_on' => '2025-06-15',
            'description' => 'Old damage.',
        ])->assertStatus(422)->assertJsonStructure(['message']);
    }
}
