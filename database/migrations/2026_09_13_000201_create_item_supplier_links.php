<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who the business buys each product from, and how long they take.
 *
 * Suppliers are contacts — there is no separate supplier table anywhere else
 * in procurement and none is invented here. This is a link table between the
 * catalogue and those contacts, carrying the two facts replenishment cannot
 * work without: the lead time (whether "below reorder" is a note or an
 * emergency depends entirely on it) and the last agreed price (so a suggested
 * requisition can be costed well enough to route to the right approver).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            /*
             * The level to order back up to. Separate from reorder_level
             * because they answer different questions: reorder_level is
             * "when do I worry", max_level is "how much do I hold". Nullable —
             * a business that never says falls back to the reorder level
             * itself, which just tops the shelf up to the alarm line.
             */
            $table->decimal('max_level', 15, 3)->nullable()->after('reorder_level');
        });

        Schema::create('item_suppliers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('item_id')->constrained('items')->cascadeOnDelete();
            $table->foreignUlid('supplier_id')->constrained('contacts')->cascadeOnDelete();

            /*
             * Order to doorstep, in days. The figure that turns "we are low"
             * into "we will run out before the replacement can arrive", which
             * is the only distinction on the replenishment screen that
             * changes what somebody does today.
             */
            $table->unsignedSmallInteger('lead_days')->default(7);

            // What they charged last time, or what was agreed. An estimate
            // for the requisition, never a committed price — that is what
            // quotations and purchase orders are for.
            $table->decimal('last_price', 15, 2)->nullable();

            $table->boolean('is_preferred')->default(false);
            $table->string('notes')->nullable();
            $table->timestamps();

            // One row per pairing: two rows would be two answers to "how long
            // does this supplier take".
            $table->unique(['item_id', 'supplier_id'], 'item_suppliers_unq');
            $table->index(['company_id', 'supplier_id'], 'item_suppliers_supplier_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_suppliers');

        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn('max_level');
        });
    }
};
