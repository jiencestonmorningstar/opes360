<?php

namespace Tests\Feature\Orders;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Livewire\Orders\Index as OrdersIndex;
use App\Livewire\Orders\Show as OrderShow;
use App\Models\Company;
use App\Models\Contact;
use App\Models\DeliveryNote;
use App\Models\Item;
use App\Models\Role;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\StockMovement;
use App\Models\StockReservation;
use App\Models\User;
use App\Services\DocumentIssuer;
use App\Services\Orders\Fulfilment;
use App\Services\Stock\StockLedger;
use App\Services\Stock\StockValuation;
use App\Support\CurrentCompany;
use App\Support\FulfilmentBoard;
use App\Support\Modules;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * The outbound half of supply chain, tested against the claims that matter:
 * confirming consumes the EXISTING reservations mechanism, delivering writes
 * ordinary stock movements the valuation agrees with, invoicing produces an
 * ordinary Document through the one issuer without moving stock a second
 * time, and the backordered remainder is always a named, visible figure.
 */
class FulfilmentTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Company $company;

    protected Contact $customer;

    protected Item $cement;

    protected Item $rebar;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->owner = User::factory()->create();
        $this->company = Company::create([
            'slug' => 'depot-'.Str::lower(Str::random(4)),
            'name' => 'Depot Central Sarl',
            'owner_id' => $this->owner->id,
            'currency' => 'XAF',
            'plan' => 'business',
            'account_type' => 'active',
            // The module ships off; switched on for the business under test.
            'modules' => ['orders' => true, 'products' => true, 'sales' => true],
        ]);
        Modules::flush();

        $this->joinCompany($this->company, $this->owner, Role::OWNER);
        $this->owner->forceFill(['current_company_id' => $this->company->id])->save();
        app(CurrentCompany::class)->set($this->company);

        // The orders gates are registered by the integrator alongside the
        // routes (see docs/handoff/orders.md). Defined here so these tests
        // exercise the feature, not the wiring.
        foreach (['orders.view', 'orders.manage', 'orders.confirm', 'orders.deliver'] as $ability) {
            Gate::define($ability, fn (User $user) => true);
        }

        // The blades link between the two screens and to the print view; the
        // routes belong to the integrator, so the names are registered here.
        Route::get('/orders', OrdersIndex::class)->name('orders');
        Route::get('/orders/{orderId}', OrderShow::class)->name('orders.show');
        Route::get('/orders/delivery-notes/{note}/print', fn () => '')->name('orders.delivery-note');

        $this->customer = Contact::create([
            'company_id' => $this->company->id,
            'type' => 'customer',
            'name' => 'Chantier Mbarga',
        ]);

        $this->cement = $this->product('Ciment 50kg', 'CIM', price: 6500);
        $this->rebar = $this->product('Fer à béton 12mm', 'FER', price: 4000);
    }

    protected function product(string $name, string $sku, float $price): Item
    {
        return Item::create([
            'company_id' => $this->company->id,
            'name' => $name,
            'sku' => $sku,
            'type' => 'product',
            'price' => $price,
            'track_stock' => true,
            'is_active' => true,
        ]);
    }

    /** Stock arrives through the same door a supplier delivery uses. */
    protected function stockUp(Item $item, float $quantity, float $unitCost): void
    {
        app(StockLedger::class)->receive($this->company, $item, $quantity, $unitCost, actor: $this->owner);
    }

    protected function draftOrder(array $lines): SalesOrder
    {
        return app(Fulfilment::class)->create([
            'contact_id' => $this->customer->id,
            'lines' => $lines,
        ], $this->owner);
    }

    // ─────────────────────────────────────────────────────── confirming ──

    public function test_confirming_reserves_what_exists_and_names_the_backordered_remainder(): void
    {
        $this->stockUp($this->cement, 6, 5000);

        $order = $this->draftOrder([
            ['item_id' => $this->cement->id, 'quantity' => 10],
        ]);

        $shortages = app(Fulfilment::class)->confirm($order, $this->owner);

        // The remainder is named, never silent.
        $this->assertSame([['item' => 'Ciment 50kg', 'short' => 4.0]], $shortages);

        $line = $order->fresh()->lines->first();
        $this->assertSame('6.000', (string) $line->quantity_reserved);
        $this->assertSame('4.000', (string) $line->quantity_backordered);

        // Held through the EXISTING reservations mechanism — a live
        // StockReservation row, not a parallel table.
        $reservation = StockReservation::query()
            ->where('reference_type', SalesOrderLine::class)
            ->where('reference_id', $line->id)
            ->first();

        $this->assertNotNull($reservation);
        $this->assertTrue($reservation->isHolding());
        $this->assertSame('6.000', (string) $reservation->quantity);

        // Stock on hand is untouched: a promise is not a movement.
        $this->assertSame(6.0, $this->cement->stockOnHand());
    }

    public function test_confirming_refuses_to_promise_the_same_unit_twice(): void
    {
        $this->stockUp($this->cement, 6, 5000);

        $first = $this->draftOrder([['item_id' => $this->cement->id, 'quantity' => 6]]);
        app(Fulfilment::class)->confirm($first, $this->owner);

        $second = $this->draftOrder([['item_id' => $this->cement->id, 'quantity' => 6]]);
        $shortages = app(Fulfilment::class)->confirm($second, $this->owner);

        // Everything is already spoken for: the whole second order backorders.
        $this->assertSame([['item' => 'Ciment 50kg', 'short' => 6.0]], $shortages);
        $this->assertSame('0.000', (string) $second->fresh()->lines->first()->quantity_reserved);
    }

    // ─────────────────────────────────────────────────────── delivering ──

    public function test_delivering_moves_real_stock_and_the_valuation_agrees(): void
    {
        $this->stockUp($this->cement, 10, 5000);

        $order = $this->draftOrder([['item_id' => $this->cement->id, 'quantity' => 10]]);
        app(Fulfilment::class)->confirm($order, $this->owner);

        $note = app(Fulfilment::class)->deliver($order, actor: $this->owner);

        // A numbered delivery note with a verification token for its QR.
        $this->assertStringStartsWith('DLV-', $note->number);
        $this->assertNotNull($note->fresh()->verificationToken);

        // Ordinary movements, referenced to the note, reason 'sale'.
        $movement = StockMovement::query()
            ->where('reference_type', DeliveryNote::class)
            ->where('reference_id', $note->id)
            ->firstOrFail();

        $this->assertSame('sale', $movement->reason);
        $this->assertSame('-10.000', (string) $movement->quantity);

        // The shelf and the valuation agree without knowing orders exist.
        $this->assertSame(0.0, $this->cement->stockOnHand());
        $quantities = app(StockValuation::class)->quantities($this->company);
        $this->assertSame(0.0, (float) ($quantities[$this->cement->id] ?? 0.0));

        // The reservation is fulfilled, not still holding.
        $this->assertSame(0, StockReservation::query()->holding()->count());

        $this->assertSame(SalesOrder::STATUS_DELIVERED, $order->fresh()->status);
    }

    public function test_partial_delivery_keeps_the_rest_reserved_and_the_order_open(): void
    {
        $this->stockUp($this->cement, 10, 5000);

        $order = $this->draftOrder([['item_id' => $this->cement->id, 'quantity' => 10]]);
        app(Fulfilment::class)->confirm($order, $this->owner);

        $line = $order->fresh()->lines->first();
        app(Fulfilment::class)->deliver($order, [$line->id => 4], $this->owner);

        $line = $line->fresh();
        $this->assertSame('4.000', (string) $line->quantity_delivered);
        $this->assertSame('6.000', (string) $line->quantity_reserved);

        // The remainder is still held by a live reservation.
        $this->assertSame(6.0, (float) StockReservation::query()->holding()->sum('quantity'));
        $this->assertSame(SalesOrder::STATUS_PICKING, $order->fresh()->status);
        $this->assertSame(6.0, $this->cement->stockOnHand());
    }

    public function test_delivery_cannot_exceed_what_is_held(): void
    {
        $this->stockUp($this->cement, 6, 5000);

        $order = $this->draftOrder([['item_id' => $this->cement->id, 'quantity' => 10]]);
        app(Fulfilment::class)->confirm($order, $this->owner);

        $line = $order->fresh()->lines->first();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Only 6/');

        app(Fulfilment::class)->deliver($order, [$line->id => 10], $this->owner);
    }

    // ──────────────────────────────────────────────────────── invoicing ──

    public function test_the_invoice_is_an_ordinary_document_matching_the_delivered_quantities(): void
    {
        $this->stockUp($this->cement, 10, 5000);
        $this->stockUp($this->rebar, 20, 3000);

        $order = $this->draftOrder([
            ['item_id' => $this->cement->id, 'quantity' => 10, 'unit_price' => 6500],
            ['item_id' => $this->rebar->id, 'quantity' => 20, 'unit_price' => 4000],
        ]);
        app(Fulfilment::class)->confirm($order, $this->owner);

        // Deliver only part of the rebar: the invoice must bill what went,
        // not what was ordered.
        $lines = $order->fresh()->lines;
        app(Fulfilment::class)->deliver($order, [
            $lines[0]->id => 10,
            $lines[1]->id => 15,
        ], $this->owner);

        $invoice = app(Fulfilment::class)->invoice($order, $this->owner);

        $this->assertSame(DocumentType::Invoice, $invoice->type);
        $this->assertSame(DocumentStatus::Draft, $invoice->status);
        $this->assertSame($this->customer->id, $invoice->contact_id);

        $quantities = $invoice->lines->pluck('quantity')->map(fn ($q) => (float) $q)->all();
        $this->assertSame([10.0, 15.0], $quantities);

        // 10 × 6500 + 15 × 4000 — priced like every other invoice.
        $this->assertSame(125000.0, (float) $invoice->total);

        // Linked, never copied.
        $this->assertTrue($order->fresh()->invoices->contains('id', $invoice->id));

        // Issuing through the one issuer numbers it — and must NOT move the
        // stock a second time: the delivery already wrote the movements.
        $onHandBefore = $this->cement->stockOnHand();
        app(DocumentIssuer::class)->issue($invoice, $this->owner);

        $this->assertStringStartsWith('INV-', $invoice->fresh()->number);
        $this->assertSame($onHandBefore, $this->cement->stockOnHand());
    }

    public function test_invoicing_twice_refuses_the_second_time(): void
    {
        $this->stockUp($this->cement, 10, 5000);

        $order = $this->draftOrder([['item_id' => $this->cement->id, 'quantity' => 10]]);
        app(Fulfilment::class)->confirm($order, $this->owner);
        app(Fulfilment::class)->deliver($order, actor: $this->owner);
        app(Fulfilment::class)->invoice($order, $this->owner);

        $this->assertSame(SalesOrder::STATUS_INVOICED, $order->fresh()->status);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/already on an invoice/');

        app(Fulfilment::class)->invoice($order, $this->owner);
    }

    // ─────────────────────────────────────────────────────── cancelling ──

    public function test_cancelling_releases_the_reservations(): void
    {
        $this->stockUp($this->cement, 10, 5000);

        $order = $this->draftOrder([['item_id' => $this->cement->id, 'quantity' => 10]]);
        app(Fulfilment::class)->confirm($order, $this->owner);

        $this->assertSame(10.0, (float) StockReservation::query()->holding()->sum('quantity'));

        app(Fulfilment::class)->cancel($order, $this->owner);

        $this->assertSame(SalesOrder::STATUS_CANCELLED, $order->fresh()->status);
        $this->assertSame(0, StockReservation::query()->holding()->count());
        // The shelf never moved.
        $this->assertSame(10.0, $this->cement->stockOnHand());
    }

    public function test_an_order_with_deliveries_cannot_be_cancelled(): void
    {
        $this->stockUp($this->cement, 10, 5000);

        $order = $this->draftOrder([['item_id' => $this->cement->id, 'quantity' => 10]]);
        app(Fulfilment::class)->confirm($order, $this->owner);
        $line = $order->fresh()->lines->first();
        app(Fulfilment::class)->deliver($order, [$line->id => 4], $this->owner);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/credit the invoice/');

        app(Fulfilment::class)->cancel($order, $this->owner);
    }

    // ──────────────────────────────────────────────────────── the board ──

    public function test_the_board_reports_promised_shippable_and_short(): void
    {
        $this->stockUp($this->cement, 6, 5000);

        $order = $this->draftOrder([['item_id' => $this->cement->id, 'quantity' => 10]]);
        app(Fulfilment::class)->confirm($order, $this->owner);

        $rows = (new FulfilmentBoard($this->company))->rows();

        $this->assertCount(1, $rows);
        $this->assertSame(10.0, $rows[0]['promised']);
        $this->assertSame(6.0, $rows[0]['shippable']);
        $this->assertSame(4.0, $rows[0]['short']);
        $this->assertSame('Ciment 50kg', $rows[0]['shortages'][0]['item']->name);
    }

    public function test_the_board_says_whether_replenishment_already_covers_a_shortage(): void
    {
        $this->stockUp($this->cement, 6, 5000);
        $this->cement->forceFill(['reorder_level' => 5])->save();

        $order = $this->draftOrder([['item_id' => $this->cement->id, 'quantity' => 10]]);
        app(Fulfilment::class)->confirm($order, $this->owner);

        // Available is 0 (6 on hand, 6 reserved) — below the reorder level,
        // so the existing Replenishment read model lists it and the board
        // reads that answer rather than forming its own.
        $rows = (new FulfilmentBoard($this->company))->rows();

        $this->assertTrue($rows[0]['shortages'][0]['replenishment_covers']);
    }

    // ────────────────────────────────────────────────────────── screens ──

    public function test_an_order_can_be_drafted_and_worked_from_the_screens(): void
    {
        $this->stockUp($this->cement, 10, 5000);

        Livewire::actingAs($this->owner)
            ->test(OrdersIndex::class)
            ->call('startDrafting')
            ->set('contactId', $this->customer->id)
            ->set('lines', [['item_id' => $this->cement->id, 'quantity' => '10', 'unit_price' => '6500']])
            ->call('save')
            ->assertHasNoErrors();

        $order = SalesOrder::firstOrFail();
        $this->assertSame(SalesOrder::STATUS_DRAFT, $order->status);
        $this->assertStringStartsWith('SO-', $order->number);

        Livewire::actingAs($this->owner)
            ->test(OrderShow::class, ['orderId' => $order->id])
            ->call('confirm')
            ->assertHasNoErrors()
            ->call('startDelivering')
            ->call('deliver')
            ->assertHasNoErrors();

        $this->assertSame(SalesOrder::STATUS_DELIVERED, $order->fresh()->status);
        $this->assertSame(1, DeliveryNote::query()->count());
    }

    public function test_a_backordered_remainder_is_visible_on_the_screen(): void
    {
        $this->stockUp($this->cement, 6, 5000);

        $order = $this->draftOrder([['item_id' => $this->cement->id, 'quantity' => 10]]);
        app(Fulfilment::class)->confirm($order, $this->owner);

        Livewire::actingAs($this->owner)
            ->test(OrderShow::class, ['orderId' => $order->id])
            ->assertSee('backordered');
    }

    // ──────────────────────────────────────────────────────── isolation ──

    public function test_a_second_company_sees_nothing(): void
    {
        $this->stockUp($this->cement, 10, 5000);
        $order = $this->draftOrder([['item_id' => $this->cement->id, 'quantity' => 5]]);
        app(Fulfilment::class)->confirm($order, $this->owner);
        app(Fulfilment::class)->deliver($order, actor: $this->owner);

        $stranger = User::factory()->create();
        $other = Company::create([
            'slug' => 'autre-'.Str::lower(Str::random(4)),
            'name' => 'Autre Sarl',
            'owner_id' => $stranger->id,
            'currency' => 'XAF',
            'plan' => 'business',
            'account_type' => 'active',
            'modules' => ['orders' => true, 'products' => true, 'sales' => true],
        ]);
        Modules::flush();
        $this->joinCompany($other, $stranger, Role::OWNER);
        $stranger->forceFill(['current_company_id' => $other->id])->save();
        app(CurrentCompany::class)->set($other);

        $this->assertSame(0, SalesOrder::query()->count());
        $this->assertSame(0, DeliveryNote::query()->count());
        $this->assertCount(0, (new FulfilmentBoard($other))->rows());

        Livewire::actingAs($stranger)
            ->test(OrdersIndex::class)
            ->assertDontSee('Chantier Mbarga')
            ->assertDontSee($order->number);
    }
}
