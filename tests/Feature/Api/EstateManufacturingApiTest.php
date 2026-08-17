<?php

namespace Tests\Feature\Api;

use App\Models\BillOfMaterial;
use App\Models\Property;
use App\Models\PropertyUnit;
use App\Models\Tenancy;

/**
 * The two register-shaped verticals over the token API: reading the property
 * book, and running a recipe from raise to completion.
 */
class EstateManufacturingApiTest extends Wave4ApiTestCase
{
    // ── Estate ───────────────────────────────────────────────────────────

    public function test_properties_and_tenancies_can_be_read(): void
    {
        $landlord = $this->makeContact('M. Essomba');
        $tenant = $this->makeContact('Mme Fouda');

        $property = Property::create([
            'name' => 'Immeuble Bastos',
            'kind' => 'residential',
            'landlord_contact_id' => $landlord->id,
        ]);

        $unit = PropertyUnit::create([
            'property_id' => $property->id,
            'label' => 'Apartment 3B',
            'status' => 'occupied',
        ]);

        Tenancy::create([
            'property_unit_id' => $unit->id,
            'tenant_contact_id' => $tenant->id,
            'rent' => 150000,
            'deposit_amount' => 300000,
            'status' => 'active',
            'moved_in_on' => '2026-01-01',
        ]);

        $this->getJson('/api/v1/estate/properties')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Immeuble Bastos')
            ->assertJsonPath('data.0.units.0.label', 'Apartment 3B');

        $this->getJson("/api/v1/estate/properties/{$property->id}")
            ->assertOk()
            ->assertJsonPath('data.landlord_contact_id', $landlord->id);

        $this->getJson('/api/v1/estate/tenancies?status=active')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.rent', 150000)
            ->assertJsonPath('data.0.unit.label', 'Apartment 3B');
    }

    // ── Manufacturing ────────────────────────────────────────────────────

    protected function recipe(): BillOfMaterial
    {
        $table = $this->makeStockedItem('Table', onHand: 0, price: 60000);
        $plank = $this->makeStockedItem('Planche', onHand: 40, cost: 2000);
        $legs = $this->makeStockedItem('Pied de table', onHand: 40, cost: 1500);

        $bom = BillOfMaterial::create(['item_id' => $table->id, 'name' => 'Table standard']);
        $bom->lines()->create(['item_id' => $plank->id, 'quantity' => 3, 'scrap_percent' => 0]);
        $bom->lines()->create(['item_id' => $legs->id, 'quantity' => 4, 'scrap_percent' => 0]);

        return $bom;
    }

    public function test_a_production_order_is_raised_from_the_recipe(): void
    {
        $bom = $this->recipe();

        $this->getJson('/api/v1/manufacturing/boms')
            ->assertOk()->assertJsonCount(1, 'data');

        $this->postJson('/api/v1/manufacturing/orders', [
            'bill_of_material_id' => $bom->id,
            'quantity' => 5,
        ])->assertCreated()
            ->assertJsonPath('data.status', 'planned')
            // The recipe was snapshotted onto the lines: 5 tables want 15 planks.
            ->assertJsonPath('data.lines.0.quantity_required', 15);
    }

    public function test_completing_consumes_components_and_prices_the_finished_goods(): void
    {
        $bom = $this->recipe();

        $id = $this->postJson('/api/v1/manufacturing/orders', [
            'bill_of_material_id' => $bom->id,
            'quantity' => 2,
        ])->json('data.id');

        // 2 tables: 6 planks at 2,000 + 8 legs at 1,500 = 24,000, 12,000 each.
        $this->postJson("/api/v1/manufacturing/orders/{$id}/complete")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.total_cost', 24000)
            ->assertJsonPath('data.unit_cost', 12000);

        // A second completion is refused — the goods already moved.
        $this->postJson("/api/v1/manufacturing/orders/{$id}/complete")
            ->assertStatus(422);
    }

    public function test_a_short_shelf_refuses_the_whole_completion(): void
    {
        $bom = $this->recipe();

        $id = $this->postJson('/api/v1/manufacturing/orders', [
            'bill_of_material_id' => $bom->id,
            'quantity' => 20, // wants 60 planks; only 40 exist
        ])->json('data.id');

        $this->postJson("/api/v1/manufacturing/orders/{$id}/complete")
            ->assertStatus(422)
            ->assertJsonStructure(['message']);
    }
}
