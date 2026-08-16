<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The paper that travels with the goods.
 *
 * A delivery note records what physically left, when, and against which
 * order. It carries its own leased number (the same ledger every other
 * numbered paper draws from) and a verification token, so the person
 * receiving a truck can scan the QR and know the paper is the business's own.
 *
 * The note itself moves nothing: the StockMovements written in the same
 * transaction as the note are what took the goods off the shelf, and each of
 * them points back here — reference_type/reference_id — the way a stocktake's
 * movements point at the stocktake.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_notes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('sales_order_id')->constrained()->cascadeOnDelete();
            $table->string('number');
            // 'issued' the moment it exists — a delivery note is a record of a
            // fact, not a draft of one. 'void' keeps a mistaken note printable
            // with the mark that says so, instead of deletable.
            $table->string('status')->default('issued');
            $table->date('delivered_on');
            $table->foreignUlid('stock_location_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('verification_token_id')->nullable()->constrained()->nullOnDelete();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'sales_order_id']);
        });

        Schema::create('delivery_note_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('delivery_note_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('sales_order_line_id')->constrained('sales_order_lines')->cascadeOnDelete();
            $table->foreignUlid('item_id')->constrained('items')->cascadeOnDelete();
            $table->string('description');
            $table->string('unit')->default('unit');
            $table->decimal('quantity', 15, 3);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('delivery_note_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_note_lines');
        Schema::dropIfExists('delivery_notes');
    }
};
