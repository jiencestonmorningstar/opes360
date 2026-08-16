<?php

namespace Tests\Feature\SupplyChain;

use App\Events\DomainEvent;
use App\Listeners\SyncApprovedRequisitions;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Item;
use App\Models\ItemSupplier;
use App\Models\PurchaseRequisition;
use App\Models\Role;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowInstance;
use App\Services\Procurement\Replenisher;
use App\Services\Stock\StockReservations;
use App\Support\CurrentCompany;
use App\Support\Replenishment;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Nothing beyond reorder levels — until now.
 *
 * The read model watches the shelf; the service turns a checked suggestion
 * into an ordinary draft requisition through the existing procurement door.
 * The tests that matter most are the ones proving there is no second door:
 * accepting a suggestion produces a PurchaseRequisition that then submits to
 * the shared workflow engine like any other.
 */
class ReplenishmentTest extends TestCase
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
        ]);

        $this->joinCompany($this->company, $this->owner, Role::OWNER);
        $this->owner->forceFill(['current_company_id' => $this->company->id])->save();
        app(CurrentCompany::class)->set($this->company);

        // Registered here because AppServiceProvider belongs to the
        // integrating agent, exactly as in ProcurementRequisitionTest.
        Event::listen(DomainEvent::class, SyncApprovedRequisitions::class);
    }

    // ---------------------------------------------------------------- helpers

    protected function item(string $name, float $reorder, ?float $max = null): Item
    {
        return Item::create([
            'company_id' => $this->company->id,
            'type' => 'product',
            'name' => $name,
            'unit' => 'unit',
            'price' => 1000,
            'cost' => 600,
            'track_stock' => true,
            'reorder_level' => $reorder,
            'max_level' => $max,
            'is_active' => true,
        ]);
    }

    protected function move(Item $item, float $quantity, string $reason = 'purchase', ?string $occurredAt = null): StockMovement
    {
        return StockMovement::create([
            'company_id' => $this->company->id,
            'item_id' => $item->id,
            'quantity' => $quantity,
            'reason' => $reason,
            'occurred_at' => $occurredAt ?? now(),
        ]);
    }

    protected function supplier(string $name = 'Sotrafic Sarl'): Contact
    {
        return Contact::create([
            'company_id' => $this->company->id,
            'type' => 'supplier',
            'name' => $name,
        ]);
    }

    protected function link(Item $item, Contact $supplier, int $leadDays, ?float $price = null, bool $preferred = true): ItemSupplier
    {
        return ItemSupplier::create([
            'company_id' => $this->company->id,
            'item_id' => $item->id,
            'supplier_id' => $supplier->id,
            'lead_days' => $leadDays,
            'last_price' => $price,
            'is_preferred' => $preferred,
        ]);
    }

    protected function rows()
    {
        return (new Replenishment($this->company))->rows();
    }

    protected function row(Item $item): ?array
    {
        return $this->rows()->first(fn (array $row) => $row['item']->id === $item->id);
    }

    // ------------------------------------------------------------- read model

    public function test_an_item_below_its_reorder_level_is_suggested(): void
    {
        $item = $this->item('Fuel filter', reorder: 10, max: 50);
        $this->move($item, 4);

        $row = $this->row($item);

        $this->assertNotNull($row);
        $this->assertSame(4.0, $row['on_hand']);
        $this->assertSame(4.0, $row['available']);
        // Nothing is being consumed, so the suggestion is simply the gap up
        // to the maximum level.
        $this->assertSame(46.0, $row['suggested_quantity']);
    }

    public function test_an_item_with_enough_stock_is_left_alone(): void
    {
        $item = $this->item('Oil drum', reorder: 10);
        $this->move($item, 40);

        $this->assertNull($this->row($item));
    }

    public function test_reservations_reduce_the_cover(): void
    {
        $item = $this->item('Brake pads', reorder: 10, max: 30);
        $this->move($item, 14);

        // On hand is above the reorder level; the promises are what sink it.
        app(StockReservations::class)->reserve($item, 6.0);

        $row = $this->row($item);

        $this->assertNotNull($row);
        $this->assertSame(14.0, $row['on_hand']);
        $this->assertSame(6.0, $row['reserved']);
        $this->assertSame(8.0, $row['available']);
        $this->assertTrue($row['below_now']);
    }

    public function test_a_slow_supplier_is_flagged_as_will_run_out(): void
    {
        $item = $this->item('Coolant', reorder: 15, max: 60);
        $this->move($item, 40, 'purchase', now()->subDays(40)->toDateTimeString());
        // Sold steadily: 30 units across the 30-day usage window is one a day.
        $this->move($item, -30, 'sale', now()->subDays(15)->toDateTimeString());

        $supplier = $this->supplier('Slowboat Ltd');
        $this->link($item, $supplier, leadDays: 20, price: 500);

        $row = $this->row($item);

        $this->assertNotNull($row);
        $this->assertSame(10.0, $row['available']);
        $this->assertEqualsWithDelta(1.0, $row['daily_usage'], 0.01);
        $this->assertEqualsWithDelta(10.0, $row['days_of_cover'], 0.1);
        $this->assertSame(20, $row['lead_days']);
        // Ten days of stock, twenty days until a delivery: the shelf goes
        // empty before the replacement can arrive.
        $this->assertTrue($row['will_run_out']);
    }

    public function test_a_fast_supplier_is_not_flagged(): void
    {
        $item = $this->item('Grease', reorder: 15, max: 60);
        $this->move($item, 40, 'purchase', now()->subDays(40)->toDateTimeString());
        $this->move($item, -30, 'sale', now()->subDays(15)->toDateTimeString());

        $this->link($item, $this->supplier('Quickparts'), leadDays: 3);

        $row = $this->row($item);

        $this->assertNotNull($row);
        $this->assertFalse($row['will_run_out']);
    }

    public function test_an_item_projected_to_fall_below_during_the_lead_time_is_included(): void
    {
        // Available 20, reorder 15 — fine today. Selling one a day with a
        // ten-day lead time, it will be at 10 by the time an order placed
        // today could arrive.
        $item = $this->item('Air filter', reorder: 15, max: 40);
        $this->move($item, 50, 'purchase', now()->subDays(40)->toDateTimeString());
        $this->move($item, -30, 'sale', now()->subDays(15)->toDateTimeString());

        $this->link($item, $this->supplier(), leadDays: 10);

        $row = $this->row($item);

        $this->assertNotNull($row);
        $this->assertFalse($row['below_now']);
    }

    public function test_the_suggested_quantity_covers_the_lead_time(): void
    {
        $item = $this->item('Coolant', reorder: 15, max: 60);
        $this->move($item, 40, 'purchase', now()->subDays(40)->toDateTimeString());
        $this->move($item, -30, 'sale', now()->subDays(15)->toDateTimeString());
        $this->link($item, $this->supplier(), leadDays: 20);

        $row = $this->row($item);

        // Up to the maximum (60) from what is available (10), plus the twenty
        // units that will be consumed while the order is on the road.
        $this->assertSame(70.0, $row['suggested_quantity']);
    }

    // ------------------------------------------------ accepting a suggestion

    public function test_accepting_creates_one_draft_requisition_per_supplier(): void
    {
        $supplierA = $this->supplier('Sotrafic Sarl');
        $supplierB = $this->supplier('Quickparts');

        $one = $this->item('Fuel filter', reorder: 10, max: 30);
        $two = $this->item('Oil drum', reorder: 5, max: 20);
        $three = $this->item('Coolant', reorder: 8, max: 25);

        foreach ([$one, $two, $three] as $item) {
            $this->move($item, 2);
        }

        $this->link($one, $supplierA, leadDays: 7, price: 15_000);
        $this->link($two, $supplierA, leadDays: 7, price: 45_000);
        $this->link($three, $supplierB, leadDays: 3, price: 2_500);

        $requisitions = app(Replenisher::class)->accept(
            [$one->id, $two->id, $three->id],
            $this->owner,
        );

        $this->assertCount(2, $requisitions);

        $forA = $requisitions->first(fn (PurchaseRequisition $r) => $r->lines->count() === 2);
        $forB = $requisitions->first(fn (PurchaseRequisition $r) => $r->lines->count() === 1);

        $this->assertNotNull($forA);
        $this->assertNotNull($forB);
        $this->assertSame('draft', $forA->status);
        $this->assertSame('draft', $forB->status);

        // Priced from the supplier link, not the catalogue.
        $line = $forA->lines->firstWhere('item_id', $one->id);
        $this->assertSame(15_000.0, (float) $line->estimated_unit_price);
        $this->assertSame(28.0, (float) $line->quantity);
    }

    public function test_an_accepted_requisition_flows_the_normal_approval_path(): void
    {
        $item = $this->item('Fuel filter', reorder: 10, max: 30);
        $this->move($item, 2);
        $this->link($item, $this->supplier(), leadDays: 7, price: 15_000);

        $workflow = Workflow::create([
            'company_id' => $this->company->id,
            'name' => 'Requisition approval',
            'subject_type' => PurchaseRequisition::class,
            'is_active' => true,
        ]);
        $workflow->steps()->create([
            'company_id' => $this->company->id,
            'position' => 1,
            'name' => 'Manager sign-off',
            'type' => 'approval',
            'approver_mode' => 'role',
            'approver_role' => Role::MANAGER,
            'quorum' => 'any',
        ]);

        $requisition = app(Replenisher::class)->accept([$item->id], $this->owner)->sole();

        $instance = app(\App\Services\Procurement\Requisitions::class)
            ->submit($requisition, $workflow->fresh(), $this->owner);

        $this->assertInstanceOf(WorkflowInstance::class, $instance);
        $this->assertSame('submitted', $requisition->fresh()->status);
    }

    public function test_accepting_nothing_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        app(Replenisher::class)->accept([], $this->owner);
    }

    public function test_items_without_a_supplier_still_become_a_requisition(): void
    {
        $item = $this->item('Odd part', reorder: 10, max: 30);
        $this->move($item, 2);

        $requisitions = app(Replenisher::class)->accept([$item->id], $this->owner);

        $this->assertCount(1, $requisitions);
        $this->assertSame('draft', $requisitions->sole()->status);
        // No supplier link, so the estimate falls back to the catalogue cost.
        $this->assertSame(600.0, (float) $requisitions->sole()->lines->sole()->estimated_unit_price);
    }
}
