<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stock in more than one place.
     *
     * ── Built on the movement ledger, not beside it ──────────────────────
     *
     * `stock_movements` is already append-only and signed: two devices selling
     * the same item offline each append -1 and both are right. Locations keep
     * that property rather than working around it — a movement gains a
     * location, and the stock at a location is the sum of the movements
     * attributed to it. A per-location `quantity` column would have been a
     * mutable total, which is exactly the thing that design exists to avoid.
     *
     * The column is nullable, and null means "wherever the business keeps
     * things". Every movement recorded before this migration is that, and
     * rewriting them all to point at an invented default would be claiming to
     * know something nobody recorded.
     *
     * ── A transfer is two movements ──────────────────────────────────────
     *
     * Out of one place and into another, appended like any other movement and
     * tied together by the transfer they belong to. Nothing is created or
     * destroyed, which is the one invariant a transfer has to keep: the total
     * across all locations is unchanged by moving stock between them.
     */
    public function up(): void
    {
        Schema::create('stock_locations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('name');
            $table->string('code')->nullable();
            $table->string('kind')->default('shop'); // shop|warehouse|vehicle|other
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('manager')->nullable();
            $table->string('phone')->nullable();

            $table->boolean('is_default')->default(false);
            $table->boolean('active')->default(true);
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'active']);
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignUlid('stock_location_id')->nullable()->after('item_id')
                ->constrained('stock_locations')->nullOnDelete();

            $table->index(['company_id', 'stock_location_id', 'item_id']);
        });

        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('reference')->nullable();
            $table->foreignUlid('from_location_id')->constrained('stock_locations')->cascadeOnDelete();
            $table->foreignUlid('to_location_id')->constrained('stock_locations')->cascadeOnDelete();

            $table->date('moved_on');
            $table->string('status')->default('completed'); // completed|cancelled
            $table->text('note')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'moved_on']);
        });

        Schema::create('stock_transfer_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('stock_transfer_id')->constrained('stock_transfers')->cascadeOnDelete();
            $table->foreignUlid('item_id')->constrained('items')->cascadeOnDelete();

            $table->decimal('quantity', 15, 3);

            $table->timestamps();

            $table->index(['company_id', 'stock_transfer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_lines');
        Schema::dropIfExists('stock_transfers');

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stock_location_id');
        });

        Schema::dropIfExists('stock_locations');
    }
};
