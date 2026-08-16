<?php

namespace Tests\Feature\Logistics;

use App\Models\SearchEntry;
use App\Search\GlobalSearch;
use App\Support\Modules;

/**
 * Logistics in the global search: a shipment found by reference or by its
 * parties, a manifest by reference — behind logistics.view, never leaking
 * the tracking token.
 */
class LogisticsSearchTest extends LogisticsTestCase
{
    public function test_a_shipment_is_found_by_reference_and_by_party_name(): void
    {
        $shipment = $this->shipment();
        GlobalSearch::index($shipment);

        $byReference = GlobalSearch::query($this->owner, $this->company, $shipment->reference);
        $this->assertArrayHasKey('Shipments', $byReference);
        $this->assertSame($shipment->reference, $byReference['Shipments'][0]['title']);
        $this->assertStringContainsString('/logistics/'.$shipment->id, $byReference['Shipments'][0]['url']);

        $byParty = GlobalSearch::query($this->owner, $this->company, 'Fotso');
        $this->assertArrayHasKey('Shipments', $byParty);
    }

    public function test_a_manifest_is_found_by_reference(): void
    {
        $manifest = $this->manifest();
        GlobalSearch::index($manifest);

        $results = GlobalSearch::query($this->owner, $this->company, $manifest->reference);

        $this->assertArrayHasKey('Trip manifests', $results);
        $this->assertSame($manifest->reference, $results['Trip manifests'][0]['title']);
    }

    public function test_the_index_row_never_holds_the_tracking_token(): void
    {
        $shipment = $this->shipment();
        GlobalSearch::index($shipment);

        $entry = SearchEntry::query()
            ->where('searchable_id', (string) $shipment->id)
            ->firstOrFail();

        foreach (['title', 'subtitle', 'body'] as $column) {
            $this->assertStringNotContainsString(
                $shipment->tracking_token,
                (string) $entry->{$column},
                'A search row must not be a way to mint a public link.',
            );
        }
    }

    public function test_logistics_results_sit_behind_the_module_switch(): void
    {
        $shipment = $this->shipment();
        GlobalSearch::index($shipment);

        $this->company->forceFill(['modules' => ['logistics' => false]])->save();
        Modules::flush();

        $results = GlobalSearch::query($this->owner, $this->company->fresh(), $shipment->reference);

        $this->assertArrayNotHasKey('Shipments', $results);
    }
}
