<?php

namespace Tests\Feature\Logistics;

/**
 * The paper path: the waybill the driver hands over, and the loading sheet
 * the depot works from.
 */
class WaybillPrintTest extends LogisticsTestCase
{
    public function test_the_waybill_names_the_parties_the_cargo_and_the_tracking_link(): void
    {
        $shipment = $this->shipment(['declared_value' => 1_500_000]);

        $this->actingAs($this->owner)
            ->get(route('logistics.waybill.print', $shipment))
            ->assertOk()
            ->assertSee($shipment->reference)
            ->assertSee('Ets Fotso &amp; Fils', false)
            ->assertSee('Quincaillerie du Wouri')
            ->assertSee('40 sacks of cement')
            ->assertSee('1,500,000')
            ->assertSee($shipment->trackingUrl());
    }

    public function test_the_waybill_never_prints_the_freight_amount(): void
    {
        $shipment = $this->shipment(['freight_amount' => 250_000]);

        // The waybill travels with the goods through third hands; the money
        // lives on the invoice.
        $this->actingAs($this->owner)
            ->get(route('logistics.waybill.print', $shipment))
            ->assertOk()
            ->assertDontSee('250,000');
    }

    public function test_a_cancelled_shipment_prints_watermarked(): void
    {
        $shipment = $this->shipment();
        $this->dispatcher()->cancel($shipment, $this->owner);

        $this->actingAs($this->owner)
            ->get(route('logistics.waybill.print', $shipment->refresh()))
            ->assertOk()
            ->assertSee('CANCELLED');
    }

    public function test_the_waybill_answers_format_pdf_with_a_real_file(): void
    {
        $shipment = $this->shipment();

        $response = $this->actingAs($this->owner)
            ->get(route('logistics.waybill.print', ['shipment' => $shipment, 'format' => 'pdf']));

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->assertStringStartsWith('%PDF', (string) $response->getContent());
    }

    public function test_the_loading_sheet_lists_the_cargo_weights_and_stops(): void
    {
        $aboard = $this->shipment(['weight_kg' => 1200, 'to_location' => 'Bafoussam']);
        $also = $this->shipment(['weight_kg' => 800, 'cargo_description' => 'Roofing sheets', 'to_location' => 'Dschang']);
        $manifest = $this->manifest();
        $this->dispatcher()->load($aboard, $manifest, $this->owner);
        $this->dispatcher()->load($also, $manifest, $this->owner);

        $this->actingAs($this->owner)
            ->get(route('logistics.manifest.print', $manifest))
            ->assertOk()
            ->assertSee($manifest->reference)
            ->assertSee($aboard->reference)
            ->assertSee($also->reference)
            ->assertSee('Roofing sheets')
            ->assertSee('Bafoussam · Dschang')
            ->assertSee('2,000.0')          // total weight
            ->assertSee('2 shipment(s) aboard')
            ->assertSee('DRAFT');           // still open — not yet the plan of record
    }

    public function test_a_dispatched_loading_sheet_prints_clean(): void
    {
        $shipment = $this->shipment();
        $manifest = $this->manifest();
        $this->dispatcher()->load($shipment, $manifest, $this->owner);
        $this->dispatcher()->dispatch($manifest, $this->owner);

        $this->actingAs($this->owner)
            ->get(route('logistics.manifest.print', $manifest->refresh()))
            ->assertOk()
            ->assertDontSee('DRAFT');
    }

    public function test_the_loading_sheet_prints_no_tracking_links(): void
    {
        $shipment = $this->shipment();
        $manifest = $this->manifest();
        $this->dispatcher()->load($shipment, $manifest, $this->owner);

        // An internal working paper: it names every consignment aboard, and
        // it must not hand whoever finds it a public link per shipment.
        $this->actingAs($this->owner)
            ->get(route('logistics.manifest.print', $manifest))
            ->assertOk()
            ->assertDontSee($shipment->tracking_token);
    }
}
