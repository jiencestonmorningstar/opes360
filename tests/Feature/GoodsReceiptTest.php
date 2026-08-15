<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Document;
use App\Models\DocumentLine;
use App\Models\Expense;
use App\Models\GoodsReceipt;
use App\Models\Item;
use App\Models\Role;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Procurement\GoodsReceiver;
use App\Support\CurrentCompany;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class GoodsReceiptTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $owner;

    protected Contact $supplier;

    protected Item $item;

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

        $this->supplier = Contact::create(['name' => 'Fournisseur', 'type' => 'supplier', 'balance' => 0]);

        $this->item = Item::create([
            'name' => 'Ciment',
            'type' => 'product',
            'unit' => 'sac',
            'price' => 6000,
            'cost' => 4500,
            'track_stock' => true,
        ]);
    }

    protected function receiver(): GoodsReceiver
    {
        return app(GoodsReceiver::class);
    }

    protected function purchaseOrder(float $quantity = 100, float $unitPrice = 4500): Document
    {
        $order = Document::create([
            'type' => DocumentType::PurchaseOrder,
            'contact_id' => $this->supplier->id,
            'status' => DocumentStatus::Issued->value,
            'number' => 'PO-'.Str::upper(Str::random(5)),
            'issue_date' => now()->toDateString(),
            'currency' => 'XAF',
            'subtotal' => $quantity * $unitPrice,
            'discount_total' => 0,
            'tax_total' => 0,
            'total' => $quantity * $unitPrice,
            'amount_paid' => 0,
            'balance' => $quantity * $unitPrice,
            'created_by' => $this->owner->id,
        ]);

        DocumentLine::create([
            'document_id' => $order->id,
            'item_id' => $this->item->id,
            'description' => 'Ciment',
            'quantity' => $quantity,
            'unit' => 'sac',
            'unit_price' => $unitPrice,
            'tax_amount' => 0,
            'line_total' => $quantity * $unitPrice,
            'sort_order' => 0,
        ]);

        return $order->fresh('lines');
    }

    public function test_a_delivery_is_recorded(): void
    {
        $receipt = $this->receiver()->receive(
            supplier: $this->supplier,
            lines: [['item_id' => $this->item->id, 'description' => 'Ciment', 'quantity' => 40, 'unit_cost' => 4500]],
            actor: $this->owner,
        );

        $this->assertInstanceOf(GoodsReceipt::class, $receipt);
        $this->assertCount(1, $receipt->lines);
        $this->assertEqualsWithDelta(180000, $receipt->value(), 0.01);
    }

    /** Receiving moves stock. Ordering does not. */
    public function test_receiving_moves_stock(): void
    {
        $this->receiver()->receive(
            supplier: $this->supplier,
            lines: [['item_id' => $this->item->id, 'description' => 'Ciment', 'quantity' => 40, 'unit_cost' => 4500]],
            actor: $this->owner,
        );

        $movement = StockMovement::where('item_id', $this->item->id)->latest('occurred_at')->firstOrFail();

        $this->assertEqualsWithDelta(40, (float) $movement->quantity, 0.001);
        $this->assertSame('purchase', $movement->reason);
        $this->assertEqualsWithDelta(4500, (float) $movement->unit_cost, 0.01);
    }

    /**
     * Raising an order must not touch the shelf. Booking stock in at order
     * time is the commonest way inventory drifts away from reality.
     */
    public function test_raising_an_order_moves_no_stock(): void
    {
        $this->purchaseOrder();

        $this->assertSame(0, StockMovement::where('item_id', $this->item->id)->count());
    }

    public function test_a_service_line_is_recorded_but_moves_no_stock(): void
    {
        $delivery = Item::create([
            'name' => 'Transport', 'type' => 'service', 'unit' => 'unit',
            'price' => 15000, 'cost' => 15000, 'track_stock' => false,
        ]);

        $receipt = $this->receiver()->receive(
            supplier: $this->supplier,
            lines: [['item_id' => $delivery->id, 'description' => 'Transport', 'quantity' => 1, 'unit_cost' => 15000]],
            actor: $this->owner,
        );

        $this->assertCount(1, $receipt->lines);
        $this->assertSame(0, StockMovement::where('item_id', $delivery->id)->count());
    }

    public function test_a_line_with_no_item_is_kept_for_the_bill_to_match(): void
    {
        $receipt = $this->receiver()->receive(
            supplier: $this->supplier,
            lines: [['description' => 'Delivery charge', 'quantity' => 1, 'unit_cost' => 5000]],
            actor: $this->owner,
        );

        $this->assertCount(1, $receipt->lines);
        $this->assertNull($receipt->lines->first()->item_id);
    }

    public function test_blank_and_zero_lines_are_dropped(): void
    {
        $receipt = $this->receiver()->receive(
            supplier: $this->supplier,
            lines: [
                ['item_id' => $this->item->id, 'description' => 'Ciment', 'quantity' => 10, 'unit_cost' => 4500],
                ['description' => '', 'quantity' => 0],
            ],
            actor: $this->owner,
        );

        $this->assertCount(1, $receipt->lines);
    }

    /** A return is its own act, not a delivery of minus five. */
    public function test_a_negative_quantity_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        $this->receiver()->receive(
            supplier: $this->supplier,
            lines: [['item_id' => $this->item->id, 'description' => 'Ciment', 'quantity' => -5]],
            actor: $this->owner,
        );
    }

    public function test_receiving_against_a_non_purchase_order_is_refused(): void
    {
        $invoice = Document::create([
            'type' => DocumentType::Invoice,
            'contact_id' => $this->supplier->id,
            'status' => DocumentStatus::Issued->value,
            'number' => 'INV-1', 'issue_date' => now()->toDateString(), 'currency' => 'XAF',
            'subtotal' => 1000, 'discount_total' => 0, 'tax_total' => 0,
            'total' => 1000, 'amount_paid' => 0, 'balance' => 1000,
            'created_by' => $this->owner->id,
        ]);

        $this->expectException(RuntimeException::class);

        $this->receiver()->receive(
            supplier: $this->supplier,
            lines: [['item_id' => $this->item->id, 'description' => 'Ciment', 'quantity' => 5]],
            actor: $this->owner,
            order: $invoice,
        );
    }

    // ── Against an order ─────────────────────────────────────────────────

    public function test_a_partial_delivery_leaves_the_rest_outstanding(): void
    {
        $order = $this->purchaseOrder(100);
        $line = $order->lines->first();

        $this->receiver()->receive(
            supplier: $this->supplier,
            lines: [['item_id' => $this->item->id, 'document_line_id' => $line->id, 'description' => 'Ciment', 'quantity' => 40, 'unit_cost' => 4500]],
            actor: $this->owner,
            order: $order,
        );

        $outstanding = $this->receiver()->outstandingFor($order->fresh('lines'));

        $this->assertEqualsWithDelta(100, $outstanding[$line->id]['ordered'], 0.001);
        $this->assertEqualsWithDelta(40, $outstanding[$line->id]['received'], 0.001);
        $this->assertEqualsWithDelta(60, $outstanding[$line->id]['outstanding'], 0.001);
    }

    public function test_two_deliveries_accumulate(): void
    {
        $order = $this->purchaseOrder(100);
        $line = $order->lines->first();

        foreach ([40, 35] as $quantity) {
            $this->receiver()->receive(
                supplier: $this->supplier,
                lines: [['item_id' => $this->item->id, 'document_line_id' => $line->id, 'description' => 'Ciment', 'quantity' => $quantity, 'unit_cost' => 4500]],
                actor: $this->owner,
                order: $order,
            );
        }

        $this->assertEqualsWithDelta(
            25,
            $this->receiver()->outstandingFor($order->fresh('lines'))[$line->id]['outstanding'],
            0.001,
        );
    }

    /** An over-delivery is real; reporting a negative outstanding is not. */
    public function test_an_over_delivery_never_reports_negative_outstanding(): void
    {
        $order = $this->purchaseOrder(100);
        $line = $order->lines->first();

        $this->receiver()->receive(
            supplier: $this->supplier,
            lines: [['item_id' => $this->item->id, 'document_line_id' => $line->id, 'description' => 'Ciment', 'quantity' => 130, 'unit_cost' => 4500]],
            actor: $this->owner,
            order: $order,
        );

        $row = $this->receiver()->outstandingFor($order->fresh('lines'))[$line->id];

        $this->assertEqualsWithDelta(130, $row['received'], 0.001);
        $this->assertSame(0.0, $row['outstanding']);
    }

    /**
     * Pending and partial are different phone calls. Collapsing them into one
     * "open" status hides which one the business needs to make.
     */
    public function test_the_order_status_distinguishes_pending_partial_and_complete(): void
    {
        $order = $this->purchaseOrder(100);
        $line = $order->lines->first();

        $this->assertSame('pending', $this->receiver()->statusOf($order));

        $this->receiver()->receive(
            supplier: $this->supplier,
            lines: [['item_id' => $this->item->id, 'document_line_id' => $line->id, 'description' => 'Ciment', 'quantity' => 40, 'unit_cost' => 4500]],
            actor: $this->owner,
            order: $order,
        );

        $this->assertSame('partial', $this->receiver()->statusOf($order->fresh('lines')));

        $this->receiver()->receive(
            supplier: $this->supplier,
            lines: [['item_id' => $this->item->id, 'document_line_id' => $line->id, 'description' => 'Ciment', 'quantity' => 60, 'unit_cost' => 4500]],
            actor: $this->owner,
            order: $order,
        );

        $this->assertSame('complete', $this->receiver()->statusOf($order->fresh('lines')));
    }

    // ── Three-way match ──────────────────────────────────────────────────

    protected function bill(Document $order, float $total): Expense
    {
        return Expense::create([
            'supplier_id' => $this->supplier->id,
            'purchase_order_id' => $order->id,
            'number' => 'EXP-'.Str::upper(Str::random(5)),
            'description' => 'Ciment',
            'category' => 'stock',
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'amount' => $total, 'vat_rate' => 0, 'vat_amount' => 0,
            'total' => $total, 'amount_paid' => 0,
            'currency' => 'XAF', 'status' => 'recorded',
            'recorded_by' => $this->owner->id,
        ]);
    }

    public function test_a_clean_cycle_matches(): void
    {
        $order = $this->purchaseOrder(100, 4500);
        $line = $order->lines->first();

        $this->receiver()->receive(
            supplier: $this->supplier,
            lines: [['item_id' => $this->item->id, 'document_line_id' => $line->id, 'description' => 'Ciment', 'quantity' => 100, 'unit_cost' => 4500]],
            actor: $this->owner,
            order: $order,
        );

        $this->bill($order, 450000);

        $match = $this->receiver()->threeWayMatch($order);

        $this->assertEqualsWithDelta(450000, $match['ordered'], 0.01);
        $this->assertEqualsWithDelta(450000, $match['received'], 0.01);
        $this->assertEqualsWithDelta(450000, $match['billed'], 0.01);
        $this->assertTrue($match['matched']);
    }

    /**
     * The expensive routine mistake this whole cycle exists to catch: being
     * billed for goods that never arrived.
     */
    public function test_being_billed_for_more_than_arrived_fails_the_match(): void
    {
        $order = $this->purchaseOrder(100, 4500);
        $line = $order->lines->first();

        $this->receiver()->receive(
            supplier: $this->supplier,
            lines: [['item_id' => $this->item->id, 'document_line_id' => $line->id, 'description' => 'Ciment', 'quantity' => 70, 'unit_cost' => 4500]],
            actor: $this->owner,
            order: $order,
        );

        $this->bill($order, 450000);

        $match = $this->receiver()->threeWayMatch($order);

        $this->assertFalse($match['matched'], 'billed for 100 sacks when 70 arrived');
        $this->assertEqualsWithDelta(135000, $match['variance'], 0.01);
    }

    public function test_a_voided_bill_leaves_the_match(): void
    {
        $order = $this->purchaseOrder(100, 4500);
        $line = $order->lines->first();

        $this->receiver()->receive(
            supplier: $this->supplier,
            lines: [['item_id' => $this->item->id, 'document_line_id' => $line->id, 'description' => 'Ciment', 'quantity' => 100, 'unit_cost' => 4500]],
            actor: $this->owner,
            order: $order,
        );

        $this->bill($order, 450000)->forceFill(['status' => 'void'])->save();

        $this->assertEqualsWithDelta(0, $this->receiver()->threeWayMatch($order)['billed'], 0.01);
    }

    /** Delivery at a price other than the order's is normal, and must show. */
    public function test_a_price_change_on_delivery_shows_in_the_match(): void
    {
        $order = $this->purchaseOrder(100, 4500);
        $line = $order->lines->first();

        $this->receiver()->receive(
            supplier: $this->supplier,
            lines: [['item_id' => $this->item->id, 'document_line_id' => $line->id, 'description' => 'Ciment', 'quantity' => 100, 'unit_cost' => 4800]],
            actor: $this->owner,
            order: $order,
        );

        $match = $this->receiver()->threeWayMatch($order);

        $this->assertEqualsWithDelta(450000, $match['ordered'], 0.01);
        $this->assertEqualsWithDelta(480000, $match['received'], 0.01);
    }

    public function test_another_companys_receipts_are_invisible(): void
    {
        $this->receiver()->receive(
            supplier: $this->supplier,
            lines: [['item_id' => $this->item->id, 'description' => 'Ciment', 'quantity' => 10, 'unit_cost' => 4500]],
            actor: $this->owner,
        );

        $otherOwner = User::factory()->create();
        $other = Company::create([
            'slug' => 'other-'.Str::lower(Str::random(6)), 'name' => 'Other Sarl',
            'owner_id' => $otherOwner->id, 'currency' => 'XAF',
            'plan' => 'business', 'account_type' => 'active',
        ]);

        app(CurrentCompany::class)->set($other);

        $this->assertSame(0, GoodsReceipt::count());
    }

    public function test_receiving_without_a_company_is_refused(): void
    {
        app(CurrentCompany::class)->set(null);

        $this->expectException(RuntimeException::class);

        $this->receiver()->receive(
            supplier: $this->supplier,
            lines: [['item_id' => $this->item->id, 'description' => 'Ciment', 'quantity' => 5]],
            actor: $this->owner,
        );
    }
}
