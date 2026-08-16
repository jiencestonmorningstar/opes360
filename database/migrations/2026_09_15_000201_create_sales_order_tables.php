<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The outbound half of supply chain: a promise to a customer, line by line.
 *
 * A sales order is not a document and not a movement — it is the thing that
 * *consumes the existing machinery*: confirming reserves stock through the
 * one StockReservations service, delivering writes ordinary StockMovements,
 * and invoicing creates an ordinary Document from the delivered lines. No
 * quantity of stock is stored here; the four quantity columns on a line are
 * the order's own bookkeeping about its promise, never a shadow of the shelf.
 *
 * quantity_backordered is the point of the design: the unfulfillable
 * remainder of a confirmed order, written down where a person will see it,
 * never a silent truncation of what the customer asked for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_orders', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('contact_id')->constrained('contacts')->cascadeOnDelete();
            // Leased from the same number ledger as every other numbered
            // paper, so two clerks raising orders at once cannot collide.
            $table->string('number');
            // draft → confirmed → picking → delivered → invoiced, or cancelled.
            $table->string('status')->default('draft');
            $table->string('currency', 3);
            // When the customer was told it would ship. Informational — the
            // board reads it; nothing enforces it.
            $table->date('promised_date')->nullable();
            $table->foreignUlid('stock_location_id')->nullable()->constrained()->nullOnDelete();
            $table->text('notes')->nullable();
            // Users are BIGINT ids, not ulids.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('sales_order_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('sales_order_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('item_id')->constrained('items')->cascadeOnDelete();
            // Copied off the item at draft time so a renamed product cannot
            // restate what was promised.
            $table->string('description');
            $table->string('unit')->default('unit');
            $table->decimal('quantity_ordered', 15, 3);
            // Held by live StockReservation rows referenced to this line;
            // this column is the order's running view of them.
            $table->decimal('quantity_reserved', 15, 3)->default(0);
            $table->decimal('quantity_delivered', 15, 3)->default(0);
            // The visible remainder. Confirming an order for 10 with 6 on the
            // shelf reserves 6 and writes 4 here — by name, on the screen.
            $table->decimal('quantity_backordered', 15, 3)->default(0);
            $table->decimal('quantity_invoiced', 15, 3)->default(0);
            $table->decimal('unit_price', 15, 2);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['sales_order_id', 'sort_order'], 'sales_order_lines_order_sort_index');
            $table->index('item_id');
        });

        /*
         * Which ordinary Documents bill this order. A link table rather than a
         * column on documents: the documents table is the platform's, and an
         * order delivered in parts is invoiced in parts — several invoices,
         * one order, every one of them a first-class Document.
         */
        Schema::create('sales_order_invoices', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('sales_order_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('document_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['sales_order_id', 'document_id'], 'sales_order_invoices_order_document_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_order_invoices');
        Schema::dropIfExists('sales_order_lines');
        Schema::dropIfExists('sales_orders');
    }
};
