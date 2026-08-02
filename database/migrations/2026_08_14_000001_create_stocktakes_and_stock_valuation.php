<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the stock is worth, and the count that settles it.
 *
 * Until now the movement ledger knew how *many* of a thing a business had and
 * nothing about what any of them cost, so stock could not reach the books at
 * all: the balance sheet showed no inventory and the income statement showed
 * revenue with no cost of goods against it. Two additions fix that.
 *
 * `unit_cost` on a movement is what one unit was worth on the way in. It is
 * recorded rather than derived because cost changes between deliveries, and a
 * business that paid 400 in March and 550 in July has stock worth neither
 * figure. Outgoing movements leave it null — what a sale cost is a question
 * about the stock it came out of, not about the sale.
 *
 * The stocktake is the count itself. SYSCOHADA's ordinary presentation is the
 * inventaire intermittent: purchases are charged to 601 as they happen, and at
 * the end of a period the counted stock is carried onto the balance sheet
 * through 31, with the movement in that figure posted to 6031 so that
 * "achats moins variation" is the real cost of what was sold. That is the
 * entry this table exists to produce.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->decimal('unit_cost', 15, 2)->nullable()->after('quantity');
        });

        Schema::create('stocktakes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            // Which shelf was counted. Null means the whole business, which is
            // what a shop with one room means by an inventory.
            $table->foreignUlid('stock_location_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference');
            $table->date('counted_on');
            $table->string('status')->default('draft'); // draft|posted|void
            $table->text('note')->nullable();
            // Frozen at posting: the count's own value and the difference it
            // made, so the list reads without re-valuing history.
            $table->decimal('total_value', 15, 2)->default(0);
            $table->decimal('variance_value', 15, 2)->default(0);
            $table->decimal('opening_value', 15, 2)->default(0);
            $table->foreignId('counted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'reference']);
            $table->index(['company_id', 'status', 'counted_on']);
        });

        Schema::create('stocktake_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('stocktake_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('item_id')->constrained()->cascadeOnDelete();
            // What the ledger said before anyone walked the shelves, kept so a
            // posted count still shows what it disagreed with.
            $table->decimal('book_quantity', 15, 3)->default(0);
            $table->decimal('counted_quantity', 15, 3)->nullable();
            $table->decimal('unit_cost', 15, 2)->default(0);
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['stocktake_id', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stocktake_lines');
        Schema::dropIfExists('stocktakes');

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropColumn('unit_cost');
        });
    }
};
