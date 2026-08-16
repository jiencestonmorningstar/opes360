<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Making things out of other things.
 *
 * A bill of materials is a recipe: this finished product is made of those
 * component products, in these quantities. A production order is one run of
 * the recipe: make N of it. Both sides are ordinary catalogue items — nothing
 * here has its own idea of what stock is, and no quantity lives in these
 * tables. Completing an order writes ordinary stock movements: components out,
 * finished goods in, and the existing valuation prices both without knowing
 * manufacturing exists.
 *
 * Order lines are a snapshot of the recipe at the moment the order was
 * raised, not a join back to it. A recipe edited on Tuesday must not restate
 * what Monday's order consumed — the same reason a stocktake line freezes the
 * book quantity.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bill_of_materials', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            // What the recipe makes. A product can have several recipes —
            // sizes, seasonal variants — so no unique index here.
            $table->foreignUlid('item_id')->constrained('items')->cascadeOnDelete();
            $table->string('name')->nullable();
            // How many finished units one run of the listed quantities yields.
            // Usually 1; a bakery's recipe naturally reads "makes 20 loaves".
            $table->decimal('output_quantity', 15, 3)->default(1);
            $table->boolean('is_active')->default(true);
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'item_id']);
        });

        Schema::create('bill_of_material_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('bill_of_material_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('item_id')->constrained('items')->cascadeOnDelete();
            $table->decimal('quantity', 15, 3);
            // Waste the process expects: cutting 4 planks loses a tenth of a
            // fifth. Stated as a percentage on top of the quantity.
            $table->decimal('scrap_percent', 5, 2)->default(0);
            $table->timestamps();

            $table->unique(['bill_of_material_id', 'item_id']);
        });

        Schema::create('production_orders', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('bill_of_material_id')->constrained()->cascadeOnDelete();
            // Copied off the recipe so the order still says what it made if
            // the recipe is later retargeted or removed from view.
            $table->foreignUlid('item_id')->constrained('items')->cascadeOnDelete();
            $table->string('reference');
            $table->decimal('quantity', 15, 3);
            $table->string('status')->default('planned'); // planned|in-progress|completed|cancelled
            // Which shelf the components leave and the finished goods land on.
            // Null means the whole business, like a stocktake with no location.
            $table->foreignUlid('stock_location_id')->nullable()->constrained()->nullOnDelete();
            // Frozen at completion: the sum of what the components were worth
            // at the ledger's weighted average, and that sum per unit made.
            $table->decimal('total_cost', 15, 2)->nullable();
            $table->decimal('unit_cost', 15, 2)->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'reference']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('production_order_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('production_order_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('item_id')->constrained('items')->cascadeOnDelete();
            // The recipe's demand at the moment the order was raised, scrap
            // included — a snapshot, so editing the recipe later cannot
            // restate what this order was going to use.
            $table->decimal('quantity_required', 15, 3);
            // What completion actually took, and at what average. Null until
            // then; a cancelled order's lines keep their nulls as the proof
            // nothing moved.
            $table->decimal('quantity_consumed', 15, 3)->nullable();
            $table->decimal('unit_cost', 15, 2)->nullable();
            $table->timestamps();

            $table->unique(['production_order_id', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_order_lines');
        Schema::dropIfExists('production_orders');
        Schema::dropIfExists('bill_of_material_lines');
        Schema::dropIfExists('bill_of_materials');
    }
};
