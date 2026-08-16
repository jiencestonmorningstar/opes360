<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Item;
use App\Models\Role;
use App\Models\StockBatch;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\StockReservation;
use App\Models\User;
use App\Services\Stock\BatchLedger;
use App\Services\Stock\LocationLedger;
use App\Services\Stock\StockReservations;
use App\Support\CurrentCompany;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Knowing which stock, not just how much.
 *
 * Three things a plain quantity cannot answer: which lot a unit came from
 * (recalls), which physical unit was sold (warranties), and when it goes off
 * (FEFO). All three are opt-in per product, because a shop selling t-shirts
 * asked for none of it and must see no change at all.
 */
class StockTraceabilityTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Company $company;

    protected StockLocation $shop;

    protected StockLocation $store;

    /** Tracked by lot and expiry — the pharmacy case. */
    protected Item $drug;

    /** Tracked by serial — the electronics case. */
    protected Item $phone;

    /** Tracked not at all — the t-shirt case, and the regression canary. */
    protected Item $shirt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->owner = User::factory()->create();
        $this->company = Company::create([
            'slug' => 'trace-'.Str::lower(Str::random(4)),
            'name' => 'Pharmacie Bonanjo',
            'owner_id' => $this->owner->id,
            'currency' => 'XAF',
            'plan' => 'business',
            'account_type' => 'active',
        ]);

        $this->joinCompany($this->company, $this->owner, Role::OWNER);
        app(CurrentCompany::class)->set($this->company);

        $this->shop = StockLocation::create([
            'company_id' => $this->company->id,
            'name' => 'Officine', 'code' => 'OFF', 'kind' => 'shop',
            'is_default' => true, 'active' => true,
        ]);

        $this->store = StockLocation::create([
            'company_id' => $this->company->id,
            'name' => 'Réserve', 'code' => 'RES', 'kind' => 'warehouse', 'active' => true,
        ]);

        $this->drug = $this->item('Paracétamol 500mg', 'PAR-500', Item::TRACKING_BATCH);
        $this->phone = $this->item('Téléphone X', 'TEL-X', Item::TRACKING_SERIAL);
        $this->shirt = $this->item('T-shirt', 'TSH', Item::TRACKING_NONE);
    }

    protected function item(string $name, string $sku, string $tracking): Item
    {
        return Item::create([
            'company_id' => $this->company->id,
            'name' => $name,
            'sku' => $sku,
            'type' => 'product',
            'price' => 1000,
            'track_stock' => true,
            'is_active' => true,
            'tracking_mode' => $tracking,
        ]);
    }

    protected function batches(): BatchLedger
    {
        return app(BatchLedger::class);
    }

    protected function reservations(): StockReservations
    {
        return app(StockReservations::class);
    }

    // ───────────────────────────────────────────────────────── opt-in ──

    public function test_a_product_tracks_nothing_unless_asked(): void
    {
        $plain = Item::create([
            'company_id' => $this->company->id,
            'name' => 'Savon', 'sku' => 'SAV', 'type' => 'product',
            'price' => 500, 'track_stock' => true, 'is_active' => true,
        ]);

        $this->assertSame(Item::TRACKING_NONE, $plain->tracking_mode);
        $this->assertFalse($plain->tracksBatches());
        $this->assertFalse($plain->tracksSerials());
        $this->assertFalse($plain->isTraceable());
    }

    public function test_an_untracked_product_cannot_be_given_a_batch(): void
    {
        $this->expectException(RuntimeException::class);

        $this->batches()->receive($this->shirt, $this->shop, 10, ['code' => 'L1']);
    }

    /** The t-shirt shop's world must be exactly as it was. */
    public function test_plain_stock_still_moves_without_any_batch(): void
    {
        app(LocationLedger::class)->adjust($this->shirt, $this->shop, 25, 'opening', $this->owner);

        $this->assertSame(25.0, $this->shirt->fresh()->stockOnHand());
        $this->assertNull(StockMovement::query()->where('item_id', $this->shirt->id)->first()->stock_batch_id);
        $this->assertSame(25.0, $this->reservations()->availableOf($this->shirt));
    }

    // ────────────────────────────────────────────────────────── lots ──

    public function test_receiving_a_lot_records_where_the_quantity_came_from(): void
    {
        $batch = $this->batches()->receive($this->drug, $this->shop, 100, [
            'code' => 'LOT-A',
            'expires_on' => now()->addMonths(6)->toDateString(),
            'unit_cost' => 120,
        ], $this->owner);

        $this->assertSame('LOT-A', $batch->code);
        $this->assertSame(100.0, $this->batches()->quantityOf($batch));
        $this->assertSame(100.0, $this->drug->fresh()->stockOnHand(), 'A batch receipt is a normal movement too.');

        $movement = StockMovement::query()->where('item_id', $this->drug->id)->first();
        $this->assertSame($batch->id, $movement->stock_batch_id);
        $this->assertSame($this->shop->id, $movement->stock_location_id);
    }

    public function test_a_lot_number_is_unique_per_product(): void
    {
        $this->batches()->receive($this->drug, $this->shop, 100, ['code' => 'LOT-A']);

        $again = $this->batches()->receive($this->drug, $this->store, 40, ['code' => 'LOT-A']);

        $this->assertSame(1, StockBatch::query()->where('item_id', $this->drug->id)->count(),
            'The same lot arriving twice is more of one lot, not a second lot.');
        $this->assertSame(140.0, $this->batches()->quantityOf($again));
    }

    public function test_a_batch_tracked_product_refuses_a_receipt_with_no_lot_number(): void
    {
        $this->expectException(RuntimeException::class);

        $this->batches()->receive($this->drug, $this->shop, 10, []);
    }

    // ─────────────────────────────────────────────────────── serials ──

    public function test_each_serial_is_its_own_unit(): void
    {
        $units = $this->batches()->receiveSerials($this->phone, $this->shop, ['SN-1', 'SN-2', 'SN-3'], [], $this->owner);

        $this->assertCount(3, $units);
        $this->assertSame(3.0, $this->phone->fresh()->stockOnHand());
        $this->assertEquals([1.0, 1.0, 1.0], $units->map(fn ($b) => $this->batches()->quantityOf($b))->all());
    }

    public function test_the_same_serial_cannot_arrive_twice(): void
    {
        $this->batches()->receiveSerials($this->phone, $this->shop, ['SN-1'], [], $this->owner);

        $this->expectException(RuntimeException::class);

        $this->batches()->receiveSerials($this->phone, $this->store, ['SN-1'], [], $this->owner);
    }

    public function test_a_sold_serial_is_no_longer_on_hand(): void
    {
        $units = $this->batches()->receiveSerials($this->phone, $this->shop, ['SN-1', 'SN-2'], [], $this->owner);

        $this->batches()->issue($units->first(), 1, 'sale', $this->owner);

        $this->assertSame(0.0, $this->batches()->quantityOf($units->first()));
        $this->assertSame(1.0, $this->phone->fresh()->stockOnHand());
        $this->assertSame(['SN-2'], $this->batches()->onHandFor($this->phone)->pluck('code')->all());
    }

    // ────────────────────────────────────────────────────────── FEFO ──

    public function test_picking_takes_the_soonest_to_expire_first(): void
    {
        $late = $this->batches()->receive($this->drug, $this->shop, 50, [
            'code' => 'LOT-LATE', 'expires_on' => now()->addYear()->toDateString(),
        ]);
        $soon = $this->batches()->receive($this->drug, $this->shop, 30, [
            'code' => 'LOT-SOON', 'expires_on' => now()->addMonth()->toDateString(),
        ]);

        $picked = $this->batches()->pick($this->drug, $this->shop, 40);

        $this->assertSame('LOT-SOON', $picked[0]['batch']->code);
        $this->assertSame(30.0, $picked[0]['quantity']);
        $this->assertSame('LOT-LATE', $picked[1]['batch']->code);
        $this->assertSame(10.0, $picked[1]['quantity']);

        $this->assertSame(0.0, $this->batches()->quantityOf($soon));
        $this->assertSame(40.0, $this->batches()->quantityOf($late));
        $this->assertSame(40.0, $this->drug->fresh()->stockOnHand());
    }

    /** A lot with no expiry is not "expires never first" — it goes last. */
    public function test_a_lot_without_an_expiry_is_picked_after_the_dated_ones(): void
    {
        $this->batches()->receive($this->drug, $this->shop, 10, ['code' => 'NO-DATE']);
        $this->batches()->receive($this->drug, $this->shop, 10, [
            'code' => 'DATED', 'expires_on' => now()->addYears(5)->toDateString(),
        ]);

        $picked = $this->batches()->pick($this->drug, $this->shop, 5);

        $this->assertSame('DATED', $picked[0]['batch']->code);
    }

    public function test_picking_more_than_is_there_is_refused_and_writes_nothing(): void
    {
        $this->batches()->receive($this->drug, $this->shop, 10, ['code' => 'LOT-A']);

        try {
            $this->batches()->pick($this->drug, $this->shop, 25);
            $this->fail('Picking should have refused.');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame(10.0, $this->drug->fresh()->stockOnHand(), 'A refused pick must leave the shelf alone.');
    }

    public function test_expired_stock_is_not_picked(): void
    {
        $this->batches()->receive($this->drug, $this->shop, 10, [
            'code' => 'OLD', 'expires_on' => now()->subDay()->toDateString(),
        ]);
        $this->batches()->receive($this->drug, $this->shop, 10, [
            'code' => 'GOOD', 'expires_on' => now()->addMonth()->toDateString(),
        ]);

        $picked = $this->batches()->pick($this->drug, $this->shop, 10);

        $this->assertCount(1, $picked);
        $this->assertSame('GOOD', $picked[0]['batch']->code);
    }

    // ──────────────────────────────────────────────────────── expiry ──

    public function test_stock_about_to_go_off_is_reported(): void
    {
        $this->batches()->receive($this->drug, $this->shop, 10, [
            'code' => 'SOON', 'expires_on' => now()->addDays(10)->toDateString(),
        ]);
        $this->batches()->receive($this->drug, $this->shop, 10, [
            'code' => 'LATER', 'expires_on' => now()->addDays(200)->toDateString(),
        ]);

        $soon = $this->batches()->expiringWithin(30);

        $this->assertSame(['SOON'], $soon->pluck('code')->all());
    }

    public function test_a_lot_that_has_been_used_up_stops_being_reported(): void
    {
        $batch = $this->batches()->receive($this->drug, $this->shop, 10, [
            'code' => 'SOON', 'expires_on' => now()->addDays(3)->toDateString(),
        ]);

        $this->batches()->issue($batch, 10, 'sale', $this->owner);

        $this->assertTrue($this->batches()->expiringWithin(30)->isEmpty());
        $this->assertTrue($this->batches()->expired()->isEmpty());
    }

    public function test_expired_stock_is_listed_separately(): void
    {
        $this->batches()->receive($this->drug, $this->shop, 10, [
            'code' => 'GONE-OFF', 'expires_on' => now()->subWeek()->toDateString(),
        ]);

        $this->assertSame(['GONE-OFF'], $this->batches()->expired()->pluck('code')->all());
        $this->assertTrue($this->batches()->expiringWithin(30)->isEmpty(),
            'Already expired is a different problem from about to expire.');
    }

    // ────────────────────────────────────────────────── reservations ──

    public function test_a_reservation_holds_stock_without_moving_it(): void
    {
        app(LocationLedger::class)->adjust($this->shirt, $this->shop, 20, 'opening', $this->owner);

        $this->reservations()->reserve($this->shirt, 5, $this->shop, actor: $this->owner);

        $this->assertSame(20.0, $this->shirt->fresh()->stockOnHand(), 'Reserved stock is still on the shelf.');
        $this->assertSame(5.0, $this->reservations()->reservedOf($this->shirt));
        $this->assertSame(15.0, $this->reservations()->availableOf($this->shirt));
    }

    public function test_stock_cannot_be_promised_twice(): void
    {
        app(LocationLedger::class)->adjust($this->shirt, $this->shop, 10, 'opening', $this->owner);
        $this->reservations()->reserve($this->shirt, 8, $this->shop);

        $this->expectException(RuntimeException::class);

        $this->reservations()->reserve($this->shirt, 5, $this->shop);
    }

    public function test_releasing_a_reservation_gives_the_stock_back(): void
    {
        app(LocationLedger::class)->adjust($this->shirt, $this->shop, 10, 'opening', $this->owner);
        $held = $this->reservations()->reserve($this->shirt, 6, $this->shop);

        $this->reservations()->release($held);

        $this->assertSame(10.0, $this->reservations()->availableOf($this->shirt));
        $this->assertSame('released', $held->fresh()->status);
    }

    /**
     * An abandoned basket must not hold the last unit forever — the failure
     * mode is a shop unable to sell stock it can see on the shelf.
     */
    public function test_a_reservation_past_its_expiry_stops_holding_anything(): void
    {
        app(LocationLedger::class)->adjust($this->shirt, $this->shop, 10, 'opening', $this->owner);

        $this->reservations()->reserve($this->shirt, 9, $this->shop, expiresAt: now()->subMinute());

        $this->assertSame(10.0, $this->reservations()->availableOf($this->shirt));
        $this->assertSame(0.0, $this->reservations()->reservedOf($this->shirt));
    }

    public function test_fulfilling_a_reservation_stops_it_holding_stock(): void
    {
        app(LocationLedger::class)->adjust($this->shirt, $this->shop, 10, 'opening', $this->owner);
        $held = $this->reservations()->reserve($this->shirt, 4, $this->shop);

        $this->reservations()->fulfil($held);

        $this->assertSame('fulfilled', $held->fresh()->status);
        $this->assertSame(10.0, $this->reservations()->availableOf($this->shirt));
    }

    public function test_reservations_are_counted_per_location(): void
    {
        app(LocationLedger::class)->adjust($this->shirt, $this->shop, 10, 'opening', $this->owner);
        app(LocationLedger::class)->adjust($this->shirt, $this->store, 10, 'opening', $this->owner);

        $this->reservations()->reserve($this->shirt, 7, $this->shop);

        $this->assertSame(3.0, $this->reservations()->availableOf($this->shirt, $this->shop));
        $this->assertSame(10.0, $this->reservations()->availableOf($this->shirt, $this->store));
        $this->assertSame(13.0, $this->reservations()->availableOf($this->shirt), 'Across the business, 20 less 7.');
    }

    public function test_a_specific_lot_can_be_reserved(): void
    {
        $batch = $this->batches()->receive($this->drug, $this->shop, 20, ['code' => 'LOT-A']);

        $held = $this->reservations()->reserve($this->drug, 5, $this->shop, $batch);

        $this->assertSame($batch->id, $held->stock_batch_id);
        $this->assertSame(15.0, $this->reservations()->availableOfBatch($batch));
    }

    public function test_a_reservation_belongs_to_the_thing_it_was_made_for(): void
    {
        app(LocationLedger::class)->adjust($this->shirt, $this->shop, 10, 'opening', $this->owner);

        $held = $this->reservations()->reserve($this->shirt, 2, $this->shop, for: $this->shop);

        $this->assertSame(StockLocation::class, $held->reference_type);
        $this->assertSame($this->shop->id, $held->reference_id);
        $this->assertSame(1, StockReservation::query()->where('reference_id', $this->shop->id)->count());
    }
}
