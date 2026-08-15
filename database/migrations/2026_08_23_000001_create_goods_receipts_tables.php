<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * A purchase order is an ordinary Document of type `purchase_order`.
         * That enum case has existed since the beginning and nothing ever used
         * it; using it now means numbering, lines, printing, issuing, voiding
         * and the document history all work without a second implementation.
         *
         * What is genuinely new is the receipt: the record of what actually
         * turned up, which is rarely exactly what was ordered.
         */
        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();

            // Nullable: goods arrive against no order more often than anyone
            // admits, and refusing to record them would push the business back
            // to paper for exactly the deliveries it most needs a trail for.
            $table->foreignUlid('purchase_order_id')->nullable()
                ->constrained('documents')->nullOnDelete();

            $table->foreignUlid('supplier_id')->constrained('contacts')->cascadeOnDelete();
            $table->foreignUlid('stock_location_id')->nullable()
                ->constrained('stock_locations')->nullOnDelete();

            $table->string('number')->nullable();
            $table->string('delivery_note_ref')->nullable();
            $table->date('received_on');
            $table->text('notes')->nullable();

            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'received_on']);
            $table->index(['company_id', 'purchase_order_id']);
        });

        Schema::create('goods_receipt_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('goods_receipt_id')->constrained('goods_receipts')->cascadeOnDelete();
            $table->foreignUlid('item_id')->nullable()->constrained('items')->nullOnDelete();

            // The order line this fulfils, so a partial delivery can be matched
            // back to what it was against rather than guessed at by item.
            $table->foreignUlid('document_line_id')->nullable()
                ->constrained('document_lines')->nullOnDelete();

            $table->string('description');
            $table->decimal('quantity', 15, 3);

            /*
             * What it actually cost, which is not always what the order said.
             * Stock valuation is a weighted average and it is only meaningful
             * if each receipt carries the price actually paid.
             */
            $table->decimal('unit_cost', 15, 2)->nullable();
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['company_id', 'goods_receipt_id']);
            $table->index('document_line_id');
        });

        Schema::table('expenses', function (Blueprint $table) {
            // The third leg of the three-way match: ordered, received, billed.
            $table->foreignUlid('purchase_order_id')->nullable()->after('supplier_id')
                ->constrained('documents')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_order_id');
        });

        Schema::dropIfExists('goods_receipt_lines');
        Schema::dropIfExists('goods_receipts');
    }
};
