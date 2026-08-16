<?php

namespace Tests\Feature\Insurance;

use App\Models\InsuranceClaim;
use App\Models\InsurancePolicy;
use App\Models\SearchEntry;
use App\Search\GlobalSearch;

/**
 * Policies and claims belong in the palette: "Nkolbisson" should surface the
 * cover placed for them, and an insurer's claim reference should land on the
 * policy that claim sits under. Both entries carry insurance.view, so the
 * permission fence is the same one the screens use.
 */
class InsuranceSearchTest extends InsuranceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        GlobalSearch::observe();
    }

    public function test_a_policy_is_indexed_under_its_holder_and_product_line(): void
    {
        $policy = $this->activePolicy();

        $entry = SearchEntry::query()
            ->where('searchable_type', InsurancePolicy::class)
            ->where('searchable_id', $policy->id)
            ->first();

        $this->assertNotNull($entry, 'Binding a policy should write its search entry.');
        $this->assertStringContainsString('Transport Nkolbisson', $entry->title);
        $this->assertStringContainsString('Motor', $entry->title);
        $this->assertSame('insurance.view', $entry->ability);
        $this->assertSame('insurance.show', $entry->route_name);
    }

    public function test_a_claim_is_indexed_by_its_reference_and_routes_to_the_policy(): void
    {
        $policy = $this->activePolicy();

        $claim = $this->claims()->open($policy, [
            'incident_on' => now()->subWeek()->toDateString(),
            'description' => 'Collision at the Bonaberi roundabout.',
            'claim_number' => 'CLM-88421',
        ], $this->owner);

        $entry = SearchEntry::query()
            ->where('searchable_type', InsuranceClaim::class)
            ->where('searchable_id', $claim->id)
            ->first();

        $this->assertNotNull($entry);
        $this->assertStringContainsString('CLM-88421', $entry->title);
        $this->assertSame('insurance.view', $entry->ability);
        $this->assertSame($policy->id, $entry->route_params['policy'] ?? null);
    }

    public function test_search_finds_the_policy_for_a_permitted_user(): void
    {
        $this->activePolicy();

        $groups = GlobalSearch::query($this->owner, $this->company, 'Nkolbisson');

        $this->assertArrayHasKey('Policies', $groups);
    }

    public function test_a_deleted_policy_leaves_the_index(): void
    {
        $policy = $this->activePolicy();

        $policy->delete();

        $this->assertDatabaseMissing('search_entries', [
            'searchable_type' => InsurancePolicy::class,
            'searchable_id' => $policy->id,
        ]);
    }
}
