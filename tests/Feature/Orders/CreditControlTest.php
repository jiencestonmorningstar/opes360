<?php

namespace Tests\Feature\Orders;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Livewire\Orders\Show as OrderShow;
use App\Models\Document;
use App\Models\DocumentLine;
use App\Models\SalesOrder;
use App\Services\DocumentIssuer;
use App\Services\Orders\Fulfilment;
use Livewire\Livewire;
use RuntimeException;

/**
 * Credit control at the commitment moment: confirming an order for a
 * customer whose OVERDUE balance (the existing Aging read model) exceeds
 * their credit limit is refused unless somebody writes down why — and the
 * written-down why lands on the order. Zero or null limit = no check.
 */
class CreditControlTest extends OrdersTestCase
{
    /** An issued invoice already past its due date — real overdue debt. */
    protected function overdueInvoice(float $amount): Document
    {
        $invoice = Document::create([
            'company_id' => $this->company->id,
            'type' => DocumentType::Invoice,
            'contact_id' => $this->customer->id,
            'status' => DocumentStatus::Draft,
            'issue_date' => now()->subDays(60)->toDateString(),
            'due_date' => now()->subDays(30)->toDateString(),
            'currency' => 'XAF',
            'subtotal' => $amount,
            'total' => $amount,
            'balance' => $amount,
            'created_by' => $this->owner->id,
        ]);

        DocumentLine::create([
            'document_id' => $invoice->id,
            'description' => 'Old delivery',
            'quantity' => 1,
            'unit' => 'unit',
            'unit_price' => $amount,
            'line_total' => $amount,
            'sort_order' => 0,
        ]);

        return app(DocumentIssuer::class)->issue($invoice, $this->owner);
    }

    public function test_an_over_limit_customer_cannot_be_confirmed_without_a_reason(): void
    {
        $this->overdueInvoice(500000);
        $this->customer->forceFill(['credit_limit' => 100000])->save();

        $this->stockUp($this->cement, 10, 5000);
        $order = $this->draftOrder([['item_id' => $this->cement->id, 'quantity' => 5]]);

        try {
            app(Fulfilment::class)->confirm($order, $this->owner);
            $this->fail('An over-limit confirm went through without a reason.');
        } catch (RuntimeException $e) {
            // The refusal names both figures.
            $this->assertStringContainsString('500 000', $e->getMessage());
            $this->assertStringContainsString('credit limit', $e->getMessage());
        }

        $this->assertSame(SalesOrder::STATUS_DRAFT, $order->fresh()->status);
    }

    public function test_a_named_override_confirms_and_is_stored_on_the_order(): void
    {
        $this->overdueInvoice(500000);
        $this->customer->forceFill(['credit_limit' => 100000])->save();

        $this->stockUp($this->cement, 10, 5000);
        $order = $this->draftOrder([['item_id' => $this->cement->id, 'quantity' => 5]]);

        app(Fulfilment::class)->confirm($order, $this->owner, 'Cheque received, clearing Thursday');

        $order = $order->fresh();
        $this->assertSame(SalesOrder::STATUS_CONFIRMED, $order->status);
        $this->assertSame('Cheque received, clearing Thursday', $order->credit_override_reason);
        $this->assertSame($this->owner->id, $order->credit_override_by);
    }

    public function test_no_limit_and_within_limit_customers_are_never_checked(): void
    {
        $this->overdueInvoice(500000);
        // Null limit: the business has chosen not to check this customer.
        $this->stockUp($this->cement, 20, 5000);

        $order = $this->draftOrder([['item_id' => $this->cement->id, 'quantity' => 5]]);
        app(Fulfilment::class)->confirm($order, $this->owner);
        $this->assertSame(SalesOrder::STATUS_CONFIRMED, $order->fresh()->status);
        $this->assertNull($order->fresh()->credit_override_reason);

        // A limit above the overdue figure changes nothing either.
        $this->customer->forceFill(['credit_limit' => 900000])->save();
        $second = $this->draftOrder([['item_id' => $this->cement->id, 'quantity' => 5]]);
        app(Fulfilment::class)->confirm($second, $this->owner);
        $this->assertSame(SalesOrder::STATUS_CONFIRMED, $second->fresh()->status);
    }

    public function test_an_invoice_inside_its_terms_does_not_block(): void
    {
        // Owed, but not yet due: the aging report's 'current' bucket, which
        // the check deliberately leaves out.
        $invoice = Document::create([
            'company_id' => $this->company->id,
            'type' => DocumentType::Invoice,
            'contact_id' => $this->customer->id,
            'status' => DocumentStatus::Draft,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'currency' => 'XAF',
            'subtotal' => 500000,
            'total' => 500000,
            'balance' => 500000,
            'created_by' => $this->owner->id,
        ]);
        DocumentLine::create([
            'document_id' => $invoice->id,
            'description' => 'Fresh delivery',
            'quantity' => 1,
            'unit' => 'unit',
            'unit_price' => 500000,
            'line_total' => 500000,
            'sort_order' => 0,
        ]);
        app(DocumentIssuer::class)->issue($invoice, $this->owner);

        $this->customer->forceFill(['credit_limit' => 100000])->save();

        $this->stockUp($this->cement, 10, 5000);
        $order = $this->draftOrder([['item_id' => $this->cement->id, 'quantity' => 5]]);

        app(Fulfilment::class)->confirm($order, $this->owner);

        $this->assertSame(SalesOrder::STATUS_CONFIRMED, $order->fresh()->status);
    }

    public function test_the_screen_opens_the_override_field_on_a_credit_block(): void
    {
        $this->overdueInvoice(500000);
        $this->customer->forceFill(['credit_limit' => 100000])->save();

        $this->stockUp($this->cement, 10, 5000);
        $order = $this->draftOrder([['item_id' => $this->cement->id, 'quantity' => 5]]);

        Livewire::actingAs($this->owner)
            ->test(OrderShow::class, ['orderId' => $order->id])
            ->call('confirm')
            ->assertHasErrors('order')
            ->assertSet('creditBlocked', true)
            ->set('creditOverride', 'Owner approved by phone')
            ->call('confirm')
            ->assertHasNoErrors()
            ->assertSet('creditBlocked', false);

        $this->assertSame('Owner approved by phone', $order->fresh()->credit_override_reason);
    }
}
