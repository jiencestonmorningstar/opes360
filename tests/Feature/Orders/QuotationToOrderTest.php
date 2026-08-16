<?php

namespace Tests\Feature\Orders;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Document;
use App\Models\DocumentLine;
use App\Services\DocumentIssuer;
use App\Services\Orders\Fulfilment;
use RuntimeException;

/**
 * The sales flow's front door: an accepted quotation becomes a draft order
 * carrying its item lines and the prices the customer said yes to, linked
 * back through source_document_id — one order per quotation, and free-text
 * lines (which promise no stock) stay behind.
 */
class QuotationToOrderTest extends OrdersTestCase
{
    protected function issuedQuotation(array $lines): Document
    {
        $subtotal = collect($lines)->sum(fn (array $l) => $l['quantity'] * $l['unit_price']);

        $quotation = Document::create([
            'company_id' => $this->company->id,
            'type' => DocumentType::Quotation,
            'contact_id' => $this->customer->id,
            'status' => DocumentStatus::Draft,
            'issue_date' => now()->toDateString(),
            'currency' => 'XAF',
            'subtotal' => $subtotal,
            'total' => $subtotal,
            'balance' => 0,
            'created_by' => $this->owner->id,
        ]);

        foreach ($lines as $index => $line) {
            DocumentLine::create([
                'document_id' => $quotation->id,
                'item_id' => $line['item_id'] ?? null,
                'description' => $line['description'],
                'quantity' => $line['quantity'],
                'unit' => 'unit',
                'unit_price' => $line['unit_price'],
                'line_total' => $line['quantity'] * $line['unit_price'],
                'sort_order' => $index,
            ]);
        }

        return app(DocumentIssuer::class)->issue($quotation, $this->owner);
    }

    public function test_a_quotation_becomes_an_order_carrying_its_lines_and_prices(): void
    {
        // Quoted below catalogue price (6 500): the accepted figure travels.
        $quotation = $this->issuedQuotation([
            ['item_id' => $this->cement->id, 'description' => 'Ciment 50kg', 'quantity' => 8, 'unit_price' => 6000],
            ['description' => 'Livraison sur chantier', 'quantity' => 1, 'unit_price' => 15000],
        ]);

        $order = app(Fulfilment::class)->fromQuotation($quotation, $this->owner);

        $this->assertSame($quotation->id, $order->source_document_id);
        $this->assertSame($this->customer->id, $order->contact_id);
        $this->assertStringStartsWith('SO-', $order->number);

        // Only the item-bearing line crossed; the free-text delivery charge
        // promises no stock and stays on the quotation.
        $this->assertCount(1, $order->lines);
        $this->assertSame('8.000', (string) $order->lines->first()->quantity_ordered);
        $this->assertSame('6000.00', (string) $order->lines->first()->unit_price);

        // The quotation is marked accepted, like a converted-to-invoice one.
        $this->assertSame(DocumentStatus::Accepted, $quotation->fresh()->status);
    }

    public function test_one_order_per_quotation(): void
    {
        $quotation = $this->issuedQuotation([
            ['item_id' => $this->cement->id, 'description' => 'Ciment 50kg', 'quantity' => 8, 'unit_price' => 6000],
        ]);

        app(Fulfilment::class)->fromQuotation($quotation, $this->owner);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/already been raised/');

        app(Fulfilment::class)->fromQuotation($quotation->fresh(), $this->owner);
    }

    public function test_a_draft_quotation_and_an_itemless_one_are_refused(): void
    {
        $draft = Document::create([
            'company_id' => $this->company->id,
            'type' => DocumentType::Quotation,
            'contact_id' => $this->customer->id,
            'status' => DocumentStatus::Draft,
            'issue_date' => now()->toDateString(),
            'currency' => 'XAF',
            'subtotal' => 0,
            'total' => 0,
            'balance' => 0,
            'created_by' => $this->owner->id,
        ]);

        try {
            app(Fulfilment::class)->fromQuotation($draft, $this->owner);
            $this->fail('A draft quotation was converted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('not been accepted', $e->getMessage());
        }

        $itemless = $this->issuedQuotation([
            ['description' => 'Étude technique', 'quantity' => 1, 'unit_price' => 50000],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/catalogue item/');

        app(Fulfilment::class)->fromQuotation($itemless, $this->owner);
    }

    public function test_only_an_actual_quotation_converts(): void
    {
        $invoice = Document::create([
            'company_id' => $this->company->id,
            'type' => DocumentType::Invoice,
            'contact_id' => $this->customer->id,
            'status' => DocumentStatus::Draft,
            'issue_date' => now()->toDateString(),
            'currency' => 'XAF',
            'subtotal' => 0,
            'total' => 0,
            'balance' => 0,
            'created_by' => $this->owner->id,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Only a quotation/');

        app(Fulfilment::class)->fromQuotation($invoice, $this->owner);
    }
}
