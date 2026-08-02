<?php

namespace Tests\Feature;

use App\Livewire\Stock\Locations as StockLocationsScreen;
use App\Models\Company;
use App\Models\Item;
use App\Models\Role;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\User;
use App\Services\Stock\LocationLedger;
use App\Support\CurrentCompany;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * Stock in more than one place.
 *
 * The invariant everything else rests on: moving stock between locations
 * changes where it is and never how much there is. That, and the fact that a
 * location's stock is a sum of movements rather than a stored total, which is
 * what keeps two offline devices from overwriting each other.
 */
class StockLocationsTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Company $company;

    protected StockLocation $shop;

    protected StockLocation $store;

    protected Item $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->owner = User::factory()->create();
        $this->company = Company::create([
            'slug' => 'acme-'.Str::lower(Str::random(4)),
            'name' => 'Acme Sarl',
            'owner_id' => $this->owner->id,
            'currency' => 'XAF',
            'plan' => 'business',
            'account_type' => 'active',
        ]);

        $this->joinCompany($this->company, $this->owner, Role::OWNER);
        app(CurrentCompany::class)->set($this->company);

        $this->shop = StockLocation::create([
            'company_id' => $this->company->id,
            'name' => 'Boutique Akwa', 'code' => 'AKW', 'kind' => 'shop',
            'is_default' => true, 'active' => true,
        ]);

        $this->store = StockLocation::create([
            'company_id' => $this->company->id,
            'name' => 'Magasin Bonabéri', 'code' => 'BON', 'kind' => 'warehouse',
            'active' => true,
        ]);

        $this->item = Item::create([
            'company_id' => $this->company->id,
            'name' => 'Ciment 50kg',
            'sku' => 'CIM-50',
            'type' => 'product',
            'price' => 6500,
            'track_stock' => true,
            'is_active' => true,
        ]);
    }

    protected function ledger(): LocationLedger
    {
        return app(LocationLedger::class);
    }

    protected function stock(int $shop = 0, int $store = 0): void
    {
        if ($shop) {
            $this->ledger()->adjust($this->item, $this->shop, $shop, 'opening', $this->owner);
        }

        if ($store) {
            $this->ledger()->adjust($this->item, $this->store, $store, 'opening', $this->owner);
        }
    }

    // ────────────────────────────────────────────────────── the counts ──

    public function test_stock_is_counted_per_place(): void
    {
        $this->stock(shop: 40, store: 160);

        $this->assertSame(40.0, $this->ledger()->stockAt($this->shop, $this->item));
        $this->assertSame(160.0, $this->ledger()->stockAt($this->store, $this->item));
        $this->assertSame(200.0, $this->item->fresh()->stockOnHand(), 'The product total is still the whole of it.');
    }

    /**
     * A location's stock is a sum, never a stored total — the same append-only
     * rule the movement ledger already worked by, and what lets two devices
     * both sell the last unit offline and be reconciled afterwards.
     */
    public function test_two_movements_at_one_place_add_up_rather_than_overwrite(): void
    {
        $this->ledger()->adjust($this->item, $this->shop, 10, 'opening', $this->owner);
        $this->ledger()->adjust($this->item, $this->shop, -3, 'sale', $this->owner);
        $this->ledger()->adjust($this->item, $this->shop, -3, 'sale', $this->owner);

        $this->assertSame(4.0, $this->ledger()->stockAt($this->shop, $this->item));
        $this->assertSame(3, StockMovement::query()->where('stock_location_id', $this->shop->id)->count());
    }

    // ─────────────────────────────────────────────────────── transfers ──

    /** The one thing a transfer must never do is change the total. */
    public function test_a_transfer_moves_stock_without_creating_or_destroying_any(): void
    {
        $this->stock(shop: 40, store: 160);

        $before = $this->item->fresh()->stockOnHand();

        $this->ledger()->transfer($this->store, $this->shop, [
            ['item_id' => $this->item->id, 'quantity' => 60],
        ], $this->owner);

        $this->assertSame(100.0, $this->ledger()->stockAt($this->shop, $this->item));
        $this->assertSame(100.0, $this->ledger()->stockAt($this->store, $this->item));
        $this->assertSame($before, $this->item->fresh()->stockOnHand(), 'Nothing was created or destroyed.');
    }

    public function test_a_transfer_writes_two_movements_per_line(): void
    {
        $this->stock(store: 100);

        $transfer = $this->ledger()->transfer($this->store, $this->shop, [
            ['item_id' => $this->item->id, 'quantity' => 25],
        ], $this->owner);

        $movements = StockMovement::query()
            ->where('reference_type', StockTransfer::class)
            ->where('reference_id', $transfer->id)
            ->get();

        $this->assertCount(2, $movements);
        $this->assertSame(-25.0, (float) $movements->firstWhere('stock_location_id', $this->store->id)->quantity);
        $this->assertSame(25.0, (float) $movements->firstWhere('stock_location_id', $this->shop->id)->quantity);
    }

    public function test_more_cannot_be_moved_than_is_there(): void
    {
        $this->stock(store: 10);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('only 10');

        $this->ledger()->transfer($this->store, $this->shop, [
            ['item_id' => $this->item->id, 'quantity' => 40],
        ], $this->owner);
    }

    /**
     * And when it refuses, it refuses the whole transfer. Stock taken out of
     * one place and never put into the other would show up as a shortage
     * somebody spends an afternoon hunting for.
     */
    public function test_a_refused_transfer_leaves_nothing_half_done(): void
    {
        $other = Item::create([
            'company_id' => $this->company->id,
            'name' => 'Fer à béton', 'sku' => 'FER-12', 'type' => 'product',
            'price' => 4000, 'track_stock' => true, 'is_active' => true,
        ]);

        $this->stock(store: 100);
        $this->ledger()->adjust($other, $this->store, 5, 'opening', $this->owner);

        $before = StockMovement::query()->count();

        try {
            $this->ledger()->transfer($this->store, $this->shop, [
                ['item_id' => $this->item->id, 'quantity' => 20],   // fine
                ['item_id' => $other->id, 'quantity' => 50],        // not fine
            ], $this->owner);

            $this->fail('The transfer should have been refused.');
        } catch (RuntimeException) {
            // Expected.
        }

        $this->assertSame($before, StockMovement::query()->count(), 'Nothing was written.');
        $this->assertSame(0, StockTransfer::query()->count());
        $this->assertSame(100.0, $this->ledger()->stockAt($this->store, $this->item));
    }

    /** A service has no stock to be short of. */
    public function test_an_untracked_item_is_not_blocked_on_availability(): void
    {
        $service = Item::create([
            'company_id' => $this->company->id,
            'name' => 'Livraison', 'type' => 'service',
            'price' => 2000, 'track_stock' => false, 'is_active' => true,
        ]);

        $transfer = $this->ledger()->transfer($this->store, $this->shop, [
            ['item_id' => $service->id, 'quantity' => 3],
        ], $this->owner);

        $this->assertCount(1, $transfer->lines);
    }

    public function test_stock_cannot_be_transferred_to_where_it_already_is(): void
    {
        $this->stock(shop: 10);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already is');

        $this->ledger()->transfer($this->shop, $this->shop, [
            ['item_id' => $this->item->id, 'quantity' => 1],
        ], $this->owner);
    }

    public function test_a_transfer_of_nothing_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('at least one item');

        $this->ledger()->transfer($this->store, $this->shop, [
            ['item_id' => $this->item->id, 'quantity' => 0],
        ], $this->owner);
    }

    // ───────────────────────────────────────────────── what came before ──

    /**
     * Every movement recorded before the business had locations is
     * unattributed, and stays that way. Rewriting them to point at an invented
     * default would be claiming to know something nobody wrote down.
     */
    public function test_stock_recorded_before_locations_stays_unattributed(): void
    {
        StockMovement::create([
            'company_id' => $this->company->id,
            'item_id' => $this->item->id,
            'quantity' => 75,
            'reason' => 'opening',
            'occurred_at' => now()->subYear(),
        ]);

        $this->stock(shop: 25);

        $spread = $this->ledger()->spreadOf($this->item);

        $this->assertSame(25.0, $spread[$this->shop->id]);
        $this->assertSame(75.0, $spread[''], 'Reported, not hidden and not invented into a location.');
        $this->assertSame(100.0, $this->item->fresh()->stockOnHand());
    }

    public function test_a_locations_contents_lists_only_what_is_actually_there(): void
    {
        $other = Item::create([
            'company_id' => $this->company->id,
            'name' => 'Fer à béton', 'sku' => 'FER-12', 'type' => 'product',
            'price' => 4000, 'track_stock' => true, 'is_active' => true,
        ]);

        $this->stock(shop: 12);
        // Arrived and left again: a zero balance is not "in stock".
        $this->ledger()->adjust($other, $this->shop, 5, 'opening', $this->owner);
        $this->ledger()->adjust($other, $this->shop, -5, 'sale', $this->owner);

        $contents = $this->ledger()->contentsOf($this->shop);

        $this->assertCount(1, $contents);
        $this->assertSame($this->item->id, $contents->first()['item']->id);
        $this->assertSame(12.0, $contents->first()['quantity']);
    }

    // ────────────────────────────────────────────────────────── screen ──

    public function test_the_screen_adds_a_place_and_moves_stock(): void
    {
        $this->stock(store: 100);

        Livewire::actingAs($this->owner)
            ->test(StockLocationsScreen::class)
            ->call('startAdding')
            ->set('name', 'Camion 1')
            ->set('kind', 'vehicle')
            ->set('code', 'CAM1')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(3, StockLocation::query()->count());

        Livewire::actingAs($this->owner)
            ->test(StockLocationsScreen::class)
            ->call('startTransfer')
            ->set('fromId', $this->store->id)
            ->set('toId', $this->shop->id)
            ->set('transferLines', [['item_id' => $this->item->id, 'quantity' => '30']])
            ->call('saveTransfer')
            ->assertHasNoErrors();

        $this->assertSame(30.0, $this->ledger()->stockAt($this->shop, $this->item));
    }

    public function test_the_screen_refuses_a_transfer_to_the_same_place(): void
    {
        Livewire::actingAs($this->owner)
            ->test(StockLocationsScreen::class)
            ->call('startTransfer')
            ->set('fromId', $this->shop->id)
            ->set('toId', $this->shop->id)
            ->set('transferLines', [['item_id' => $this->item->id, 'quantity' => '1']])
            ->call('saveTransfer')
            ->assertHasErrors('toId');
    }

    public function test_the_screen_surfaces_a_shortage_rather_than_writing_it(): void
    {
        $this->stock(store: 5);

        Livewire::actingAs($this->owner)
            ->test(StockLocationsScreen::class)
            ->call('startTransfer')
            ->set('fromId', $this->store->id)
            ->set('toId', $this->shop->id)
            ->set('transferLines', [['item_id' => $this->item->id, 'quantity' => '50']])
            ->call('saveTransfer')
            ->assertHasErrors('transferLines');

        $this->assertSame(0, StockTransfer::query()->count());
    }

    public function test_the_first_place_added_becomes_the_default(): void
    {
        StockLocation::query()->forceDelete();

        Livewire::actingAs($this->owner)
            ->test(StockLocationsScreen::class)
            ->call('startAdding')
            ->set('name', 'Boutique')
            ->call('save');

        $this->assertTrue(StockLocation::query()->firstOrFail()->is_default);
    }

    // ─────────────────────────────────────────────────── who may do what ──

    public function test_a_sales_officer_cannot_move_stock_between_places(): void
    {
        $seller = User::factory()->create();
        $this->joinCompany($this->company, $seller, 'sales-officer');

        $this->actingAs($seller)->get(route('products.locations'))->assertForbidden();
    }

    public function test_a_manager_can(): void
    {
        $manager = User::factory()->create();
        $this->joinCompany($this->company, $manager, 'manager');

        $this->actingAs($manager)->get(route('products.locations'))->assertOk();
    }

    public function test_locations_belong_to_their_company_alone(): void
    {
        $otherOwner = User::factory()->create();
        $other = Company::create([
            'slug' => 'other-'.Str::lower(Str::random(4)),
            'name' => 'Other Sarl', 'owner_id' => $otherOwner->id,
            'currency' => 'XAF', 'plan' => 'business', 'account_type' => 'active',
        ]);
        $this->joinCompany($other, $otherOwner, Role::OWNER);
        app(CurrentCompany::class)->set($other);

        $this->assertSame(0, StockLocation::query()->count());
    }
}
