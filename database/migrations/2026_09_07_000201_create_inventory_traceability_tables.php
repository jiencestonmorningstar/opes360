<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which stock, not just how much.
     *
     * ── Opt-in, per product ──────────────────────────────────────────────
     *
     * `items.tracking_mode` defaults to 'none' and every product that already
     * exists gets that default, so a shop selling t-shirts sees nothing new:
     * no lot to type, no serial to scan, no expiry to answer for. A pharmacy
     * switches a product to 'batch'; an electronics dealer to 'serial'. The
     * services refuse to create a batch for a 'none' product rather than
     * quietly inventing one, because a half-traced product is worse than an
     * untraced one — it looks like a complete record and is not.
     *
     * ── A batch is an identity, not a total ──────────────────────────────
     *
     * `stock_batches` carries the lot number, the expiry and where it came
     * from; it deliberately has no quantity column. How much of a lot is left
     * is the sum of the movements pointing at it, exactly as stock on hand and
     * stock-at-a-location already are. A stored per-batch quantity would be a
     * mutable total, which is the concurrency bug the movement ledger exists
     * to avoid, reintroduced one level down.
     *
     * A serial is modelled as a batch of one unit with a unique code. That
     * collapses "which lot" and "which unit" into one FEFO/expiry/query path
     * instead of two nearly-identical tables, and a serial genuinely is a lot
     * size 1 — it can expire, it came from a supplier, it gets recalled.
     *
     * ── Reservations are promises, not movements ─────────────────────────
     *
     * Reserved stock is still on the shelf: a quote or a picking list has
     * promised it, nothing has left. So it is its own table rather than a
     * movement, and "available" is on-hand minus live reservations. Each
     * reservation may carry an expiry, because the failure mode of a
     * reservation without one is a shop unable to sell stock it can see,
     * held by a basket somebody abandoned last March.
     */
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            // none|batch|serial — see the class docblock; 'none' is the whole
            // of the existing behaviour and stays the default forever.
            $table->string('tracking_mode', 16)->default('none')->after('track_stock');
            $table->unsignedSmallInteger('expiry_warning_days')->nullable()->after('tracking_mode');
        });

        Schema::create('stock_batches', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('item_id')->constrained('items')->cascadeOnDelete();

            $table->string('code'); // lot number, or the serial itself
            $table->string('kind', 16)->default('batch'); // batch|serial
            $table->date('expires_on')->nullable();
            $table->date('received_on')->nullable();
            $table->date('manufactured_on')->nullable();
            $table->string('supplier_reference')->nullable();
            $table->decimal('unit_cost', 14, 2)->nullable();
            $table->text('note')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // The same lot arriving twice is more of one lot, never a second
            // one, and a serial arriving twice is a data-entry error.
            $table->unique(['company_id', 'item_id', 'code'], 'stock_batches_item_code_unique');
            $table->index(['company_id', 'expires_on'], 'stock_batches_company_expiry_idx');
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            // Null for everything that came before and for every product that
            // tracks nothing — the same honesty as the nullable location.
            $table->foreignUlid('stock_batch_id')->nullable()->after('stock_location_id')
                ->constrained('stock_batches')->nullOnDelete();

            $table->index(['stock_batch_id'], 'stock_movements_batch_idx');
        });

        Schema::create('stock_reservations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('item_id')->constrained('items')->cascadeOnDelete();

            $table->foreignUlid('stock_location_id')->nullable()
                ->constrained('stock_locations')->nullOnDelete();
            $table->foreignUlid('stock_batch_id')->nullable()
                ->constrained('stock_batches')->nullOnDelete();

            $table->decimal('quantity', 15, 3);
            $table->string('status', 16)->default('active'); // active|released|fulfilled
            $table->timestamp('expires_at')->nullable();

            $table->string('reference_type')->nullable();
            $table->ulid('reference_id')->nullable();
            $table->text('note')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['company_id', 'item_id', 'status'], 'stock_reservations_item_status_idx');
            $table->index(['reference_type', 'reference_id'], 'stock_reservations_reference_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_reservations');

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stock_batch_id');
        });

        Schema::dropIfExists('stock_batches');

        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn(['tracking_mode', 'expiry_warning_days']);
        });
    }
};
