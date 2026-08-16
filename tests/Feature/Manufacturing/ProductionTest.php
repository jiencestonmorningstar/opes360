<?php

namespace Tests\Feature\Manufacturing;

use App\Livewire\Manufacturing\Index as ManufacturingScreen;
use App\Models\BillOfMaterial;
use App\Models\BillOfMaterialLine;
use App\Models\Company;
use App\Models\Item;
use App\Models\ProductionOrder;
use App\Models\Role;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Manufacturing\Production;
use App\Services\Stock\BatchLedger;
use App\Services\Stock\StockLedger;
use App\Services\Stock\StockValuation;
use App\Support\CurrentCompany;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * Making things: a recipe, an order to cook it, and the stock moving honestly.
 *
 * The claims that matter are all about the existing ledger. Completing an
 * order writes ordinary stock movements — components out, finished goods in —
 * so stock on hand, the valuation and the count sheet all keep working without
 * knowing manufacturing exists. The finished good's cost is the sum of what
 * the components were worth at their existing weighted average, not a second
 * costing engine's opinion.
 */
class ProductionTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Company $company;

    protected Item $table;

    protected Item $plank;

    protected Item $screws;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->owner = User::factory()->create();
        $this->company = Company::create([
            'slug' => 'atelier-'.Str::lower(Str::random(4)),
            'name' => 'Atelier Bois Sarl',
            'owner_id' => $this->owner->id,
            'currency' => 'XAF',
            'plan' => 'business',
            'account_type' => 'active',
        ]);

        $this->joinCompany($this->company, $this->owner, Role::OWNER);
        $this->owner->forceFill(['current_company_id' => $this->company->id])->save();
        app(CurrentCompany::class)->set($this->company);

        // The manufacturing gates are registered by the integrator alongside
        // the routes (see docs/handoff/manufacturing-integration.md). Defined
        // here so these tests exercise the screen, not the wiring.
        foreach (['manufacturing.view', 'manufacturing.manage', 'manufacturing.complete'] as $ability) {
            Gate::define($ability, fn (User $user) => true);
        }

        $this->table = $this->product('Table basse', 'TBL', cost: null);
        $this->plank = $this->product('Planche 2m', 'PLK', cost: 1000);
        $this->screws = $this->product('Vis 40mm', 'VIS', cost: 10);
    }

    protected function product(string $name, string $sku, ?float $cost): Item
    {
        return Item::create([
            'company_id' => $this->company->id,
            'name' => $name,
            'sku' => $sku,
            'type' => 'product',
            'price' => 0,
            'cost' => $cost,
            'track_stock' => true,
            'is_active' => true,
        ]);
    }

    protected function bom(float $planks = 4, float $screws = 16, float $scrap = 0): BillOfMaterial
    {
        $bom = BillOfMaterial::create([
            'company_id' => $this->company->id,
            'item_id' => $this->table->id,
            'output_quantity' => 1,
            'is_active' => true,
        ]);

        BillOfMaterialLine::create([
            'bill_of_material_id' => $bom->id,
            'item_id' => $this->plank->id,
            'quantity' => $planks,
            'scrap_percent' => $scrap,
        ]);

        BillOfMaterialLine::create([
            'bill_of_material_id' => $bom->id,
            'item_id' => $this->screws->id,
            'quantity' => $screws,
        ]);

        return $bom->refresh();
    }

    /** Put components on the shelf through the same door a delivery uses. */
    protected function stockUp(float $planks, float $screws, float $plankCost = 1200, float $screwCost = 12): void
    {
        $ledger = app(StockLedger::class);
        $ledger->receive($this->company, $this->plank, $planks, $plankCost, actor: $this->owner);
        $ledger->receive($this->company, $this->screws, $screws, $screwCost, actor: $this->owner);
    }

    // ─────────────────────────────────────────────────────── the recipe ──

    public function test_an_order_snapshots_the_recipe_with_scrap(): void
    {
        $bom = $this->bom(planks: 4, screws: 16, scrap: 10);

        $order = app(Production::class)->create($this->company, $bom, 5, actor: $this->owner);

        $this->assertSame('planned', $order->status);
        $this->assertStringStartsWith('MO-'.now()->format('Y').'-', $order->reference);

        $plankLine = $order->lines->firstWhere('item_id', $this->plank->id);
        // 5 tables x 4 planks x 1.10 scrap = 22, frozen on the line.
        $this->assertEqualsWithDelta(22.0, (float) $plankLine->quantity_required, 0.001);
        $this->assertEqualsWithDelta(80.0, (float) $order->lines->firstWhere('item_id', $this->screws->id)->quantity_required, 0.001);
    }

    public function test_a_recipe_with_no_lines_cannot_become_an_order(): void
    {
        $bom = BillOfMaterial::create([
            'company_id' => $this->company->id,
            'item_id' => $this->table->id,
            'output_quantity' => 1,
            'is_active' => true,
        ]);

        $this->expectException(RuntimeException::class);

        app(Production::class)->create($this->company, $bom, 1);
    }

    // ────────────────────────────────────────────── can we make it now? ──

    public function test_availability_names_what_is_short_and_how_many_are_makeable(): void
    {
        $bom = $this->bom(planks: 4, screws: 16);
        $this->stockUp(planks: 10, screws: 1000);

        $rows = app(Production::class)->availability($this->company, $bom, 5);

        $plankRow = collect($rows)->firstWhere(fn ($r) => $r['item']->id === $this->plank->id);
        $this->assertEqualsWithDelta(20.0, $plankRow['required'], 0.001);
        $this->assertEqualsWithDelta(10.0, $plankRow['on_hand'], 0.001);
        $this->assertEqualsWithDelta(10.0, $plankRow['short'], 0.001);

        // 10 planks / 4 per table = 2 whole tables, screws are plentiful.
        $this->assertSame(2.0, app(Production::class)->makeable($this->company, $bom));
    }

    // ───────────────────────────────────────────────────── completion ──

    public function test_completing_consumes_components_and_receives_finished_goods_in_one_ledger(): void
    {
        $bom = $this->bom(planks: 4, screws: 16);
        $this->stockUp(planks: 20, screws: 200, plankCost: 1200, screwCost: 12);

        $production = app(Production::class);
        $order = $production->create($this->company, $bom, 3, actor: $this->owner);
        $order = $production->complete($order, $this->owner);

        $this->assertSame('completed', $order->status);

        // The shelves, read through the existing ledger only.
        $this->assertEqualsWithDelta(8.0, $this->plank->refresh()->stockOnHand(), 0.001);   // 20 - 12
        $this->assertEqualsWithDelta(152.0, $this->screws->refresh()->stockOnHand(), 0.001); // 200 - 48
        $this->assertEqualsWithDelta(3.0, $this->table->refresh()->stockOnHand(), 0.001);

        // Cost: 12 planks @ 1200 + 48 screws @ 12 = 14976, so 4992 a table —
        // and the valuation service agrees, because the receipt movement
        // carries that cost like any delivery would.
        $this->assertEqualsWithDelta(14976.0, (float) $order->total_cost, 0.01);
        $this->assertEqualsWithDelta(4992.0, (float) $order->unit_cost, 0.01);
        $this->assertEqualsWithDelta(
            4992.0,
            app(StockValuation::class)->unitCost($this->company, $this->table),
            0.01
        );

        // Every movement points back at the order, so "where did the planks
        // go" keeps having an answer.
        $written = StockMovement::query()
            ->where('reference_type', ProductionOrder::class)
            ->where('reference_id', $order->id)
            ->get();
        $this->assertCount(3, $written);
        $this->assertEqualsWithDelta(0.0, (float) $written->sum('quantity') - (3.0 - 12.0 - 48.0), 0.001);
    }

    public function test_a_short_component_refuses_by_name_and_consumes_nothing(): void
    {
        $bom = $this->bom(planks: 4, screws: 16);
        $this->stockUp(planks: 20, screws: 30); // screws are short for 3 tables (needs 48)

        $production = app(Production::class);
        $order = $production->create($this->company, $bom, 3, actor: $this->owner);

        try {
            $production->complete($order, $this->owner);
            $this->fail('A short component should have refused the completion.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Vis 40mm', $e->getMessage());
        }

        // Nothing moved: not the planks that were plentiful either.
        $this->assertSame('planned', $order->refresh()->status);
        $this->assertEqualsWithDelta(20.0, $this->plank->refresh()->stockOnHand(), 0.001);
        $this->assertEqualsWithDelta(30.0, $this->screws->refresh()->stockOnHand(), 0.001);
        $this->assertEqualsWithDelta(0.0, $this->table->refresh()->stockOnHand(), 0.001);
    }

    public function test_completing_twice_is_refused(): void
    {
        $bom = $this->bom();
        $this->stockUp(planks: 100, screws: 1000);

        $production = app(Production::class);
        $order = $production->complete($production->create($this->company, $bom, 1), $this->owner);

        $this->expectException(RuntimeException::class);

        $production->complete($order, $this->owner);
    }

    public function test_lot_tracked_components_are_consumed_first_expired_first_out(): void
    {
        $this->plank->forceFill(['tracking_mode' => Item::TRACKING_BATCH])->save();

        $batches = app(BatchLedger::class);
        $batches->receive($this->plank->refresh(), null, 6, [
            'code' => 'LOT-LATE', 'expires_on' => now()->addYear()->toDateString(), 'unit_cost' => 1200,
        ], $this->owner);
        $batches->receive($this->plank, null, 6, [
            'code' => 'LOT-SOON', 'expires_on' => now()->addDays(10)->toDateString(), 'unit_cost' => 1200,
        ], $this->owner);
        app(StockLedger::class)->receive($this->company, $this->screws, 100, 12, actor: $this->owner);

        $bom = $this->bom(planks: 4, screws: 16);
        $production = app(Production::class);
        $order = $production->complete($production->create($this->company, $bom, 1), $this->owner);

        $soon = StockBatch::query()->where('code', 'LOT-SOON')->first();
        $late = StockBatch::query()->where('code', 'LOT-LATE')->first();

        // All four planks came out of the lot that goes off first.
        $this->assertEqualsWithDelta(2.0, $batches->quantityOf($soon), 0.001);
        $this->assertEqualsWithDelta(6.0, $batches->quantityOf($late), 0.001);
        $this->assertSame('completed', $order->status);
    }

    public function test_a_lot_tracked_finished_good_needs_a_lot_number(): void
    {
        $this->table->forceFill(['tracking_mode' => Item::TRACKING_BATCH])->save();
        $bom = $this->bom();
        $this->stockUp(planks: 100, screws: 1000);

        $production = app(Production::class);
        $order = $production->create($this->company, $bom, 2, actor: $this->owner);

        try {
            $production->complete($order, $this->owner);
            $this->fail('A traced finished good must not appear without a lot.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('lot', mb_strtolower($e->getMessage()));
        }

        $order = $production->complete($order->refresh(), $this->owner, 'MADE-001');

        $made = StockBatch::query()->where('code', 'MADE-001')->first();
        $this->assertNotNull($made);
        $this->assertEqualsWithDelta(2.0, app(BatchLedger::class)->quantityOf($made), 0.001);
    }

    // ───────────────────────────────────────────────────── cancelling ──

    public function test_cancelling_consumes_nothing(): void
    {
        $bom = $this->bom();
        $this->stockUp(planks: 50, screws: 500);

        $production = app(Production::class);
        $order = $production->cancel($production->create($this->company, $bom, 2, actor: $this->owner), $this->owner);

        $this->assertSame('cancelled', $order->status);
        $this->assertSame(
            0,
            StockMovement::query()
                ->where('reference_type', ProductionOrder::class)
                ->where('reference_id', $order->id)
                ->count()
        );
        $this->assertEqualsWithDelta(50.0, $this->plank->refresh()->stockOnHand(), 0.001);

        $this->expectException(RuntimeException::class);
        $production->complete($order, $this->owner);
    }

    // ───────────────────────────────────────────────────────── screen ──

    public function test_the_screen_builds_a_recipe_and_runs_an_order_through(): void
    {
        $this->actingAs($this->owner);
        $this->stockUp(planks: 50, screws: 500);

        Livewire::test(ManufacturingScreen::class)
            ->set('tab', 'boms')
            ->call('startBom')
            ->set('bomItemId', $this->table->id)
            ->set('bomLines.0.item_id', $this->plank->id)
            ->set('bomLines.0.quantity', '4')
            ->call('addBomLine')
            ->set('bomLines.1.item_id', $this->screws->id)
            ->set('bomLines.1.quantity', '16')
            ->call('saveBom')
            ->assertHasNoErrors();

        $bom = BillOfMaterial::query()->where('item_id', $this->table->id)->first();
        $this->assertNotNull($bom);
        $this->assertCount(2, $bom->lines);

        Livewire::test(ManufacturingScreen::class)
            ->call('startOrder', $bom->id)
            ->set('orderQuantity', '3')
            ->call('createOrder')
            ->assertHasNoErrors();

        $order = ProductionOrder::query()->first();

        Livewire::test(ManufacturingScreen::class)
            ->call('complete', $order->id)
            ->assertHasNoErrors();

        $this->assertSame('completed', $order->refresh()->status);
        $this->assertEqualsWithDelta(3.0, $this->table->refresh()->stockOnHand(), 0.001);
    }

    public function test_the_screen_surfaces_a_shortage_instead_of_crashing(): void
    {
        $this->actingAs($this->owner);
        $bom = $this->bom();
        // No stock at all.
        $order = app(Production::class)->create($this->company, $bom, 1, actor: $this->owner);

        Livewire::test(ManufacturingScreen::class)
            ->call('complete', $order->id)
            ->assertHasErrors('orders');

        $this->assertSame('planned', $order->refresh()->status);
    }
}
