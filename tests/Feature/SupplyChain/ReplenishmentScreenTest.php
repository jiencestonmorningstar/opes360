<?php

namespace Tests\Feature\SupplyChain;

use App\Livewire\Stock\Replenishment as ReplenishmentScreen;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Item;
use App\Models\ItemSupplier;
use App\Models\PurchaseRequisition;
use App\Models\Role;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\CurrentCompany;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The screen over the replenishment read model.
 *
 * What matters here is the boundary: seeing the list takes products.view,
 * turning checked rows into requisitions takes
 * procurement.requisition-manage, and the requisitions it makes are ordinary
 * drafts — the screen holds no power of its own.
 */
class ReplenishmentScreenTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->owner = User::factory()->create();
        $this->company = Company::create([
            'slug' => 'acme-'.Str::lower(Str::random(6)),
            'name' => 'Acme Sarl',
            'owner_id' => $this->owner->id,
            'currency' => 'XAF',
            'plan' => 'business',
            'account_type' => 'active',
            // Accepting a suggestion raises a requisition, which sits behind
            // the procurement module's gate.
            'modules' => ['procurement' => true],
        ]);

        $this->joinCompany($this->company, $this->owner, Role::OWNER);
        $this->owner->forceFill(['current_company_id' => $this->company->id])->save();
        app(CurrentCompany::class)->set($this->company);
    }

    protected function shortItem(): Item
    {
        $item = Item::create([
            'company_id' => $this->company->id,
            'type' => 'product',
            'name' => 'Fuel filter',
            'unit' => 'unit',
            'price' => 1000,
            'cost' => 600,
            'track_stock' => true,
            'reorder_level' => 10,
            'max_level' => 30,
            'is_active' => true,
        ]);

        StockMovement::create([
            'company_id' => $this->company->id,
            'item_id' => $item->id,
            'quantity' => 2,
            'reason' => 'purchase',
            'occurred_at' => now(),
        ]);

        $supplier = Contact::create([
            'company_id' => $this->company->id,
            'type' => 'supplier',
            'name' => 'Sotrafic Sarl',
        ]);

        ItemSupplier::create([
            'company_id' => $this->company->id,
            'item_id' => $item->id,
            'supplier_id' => $supplier->id,
            'lead_days' => 7,
            'last_price' => 15_000,
            'is_preferred' => true,
        ]);

        return $item;
    }

    public function test_the_screen_lists_items_that_need_ordering(): void
    {
        $this->shortItem();

        Livewire::actingAs($this->owner)
            ->test(ReplenishmentScreen::class)
            ->assertOk()
            ->assertSee('Fuel filter')
            ->assertSee('Sotrafic Sarl');
    }

    public function test_accepting_checked_rows_creates_a_draft_requisition(): void
    {
        $item = $this->shortItem();

        Livewire::actingAs($this->owner)
            ->test(ReplenishmentScreen::class)
            ->set('selected', [$item->id => true])
            ->call('accept')
            ->assertHasNoErrors();

        $requisition = PurchaseRequisition::sole();

        $this->assertSame('draft', $requisition->status);
        $this->assertSame($item->id, $requisition->lines->sole()->item_id);
    }

    public function test_accepting_nothing_reports_an_error_instead_of_crashing(): void
    {
        Livewire::actingAs($this->owner)
            ->test(ReplenishmentScreen::class)
            ->call('accept')
            ->assertHasErrors('selected');

        $this->assertSame(0, PurchaseRequisition::count());
    }

    public function test_supply_details_can_be_edited_inline(): void
    {
        $item = $this->shortItem();

        $newSupplier = Contact::create([
            'company_id' => $this->company->id,
            'type' => 'supplier',
            'name' => 'Camrail Supplies',
        ]);

        Livewire::actingAs($this->owner)
            ->test(ReplenishmentScreen::class)
            ->call('startEdit', $item->id)
            ->set('editSupplierId', $newSupplier->id)
            ->set('editLeadDays', '3')
            ->set('editLastPrice', '12000')
            ->set('editMaxLevel', '50')
            ->call('saveEdit')
            ->assertHasNoErrors();

        $item->refresh()->load('supplierLinks');

        $this->assertSame('50.000', (string) $item->max_level);

        $link = $item->preferredSupplierLink();
        $this->assertSame($newSupplier->id, $link->supplier_id);
        $this->assertSame(3, $link->lead_days);
        $this->assertSame('12000.00', (string) $link->last_price);

        // The old preferred link was demoted, not deleted.
        $this->assertSame(1, ItemSupplier::query()
            ->where('item_id', $item->id)->where('is_preferred', true)->count());
        $this->assertSame(2, ItemSupplier::query()->where('item_id', $item->id)->count());
    }

    public function test_editing_supply_details_requires_products_update(): void
    {
        $item = $this->shortItem();

        $cashier = User::factory()->create();
        $this->joinCompany($this->company, $cashier, Role::CASHIER);
        $cashier->forceFill(['current_company_id' => $this->company->id])->save();

        Livewire::actingAs($cashier)
            ->test(ReplenishmentScreen::class)
            ->call('startEdit', $item->id)
            ->assertForbidden();
    }

    public function test_seeing_the_list_does_not_carry_the_power_to_order(): void
    {
        // A cashier can see products, so they can see the list — but turning
        // it into a requisition is procurement.requisition-manage, which the
        // till does not have.
        $item = $this->shortItem();

        $cashier = User::factory()->create();
        $this->joinCompany($this->company, $cashier, Role::CASHIER);
        $cashier->forceFill(['current_company_id' => $this->company->id])->save();

        Livewire::actingAs($cashier)
            ->test(ReplenishmentScreen::class)
            ->assertOk()
            ->set('selected', [$item->id => true])
            ->call('accept')
            ->assertForbidden();

        $this->assertSame(0, PurchaseRequisition::count());
    }
}
