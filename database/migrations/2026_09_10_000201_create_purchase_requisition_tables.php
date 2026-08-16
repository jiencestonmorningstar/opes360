<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The procurement steps that happen before a purchase order exists.
 *
 * Requisition (somebody asks) -> RFQ (the business asks suppliers) ->
 * quotations (suppliers answer) -> award -> purchase order. Purchase orders,
 * goods receipts and matching already exist and are untouched.
 *
 * Nothing here stores an approver, a decision or an approval status. The
 * shared workflow engine owns all three; the `status` columns below are a
 * cache of its answer, never a rival source of it.
 *
 * Index names are given explicitly throughout. MySQL rejects an identifier
 * over 64 characters and SQLite silently accepts it, so a generated name from
 * a `purchase_requisition_lines_*` prefix passes the test suite and fails on
 * the customer's database.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_requisitions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();

            $table->string('number');
            $table->string('title');
            $table->text('justification')->nullable();
            $table->date('needed_by')->nullable();

            $table->foreignUlid('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('cost_centre_id')->nullable()->constrained()->nullOnDelete();

            // draft|submitted|returned|approved|rejected|sourcing|ordered|cancelled
            $table->string('status')->default('draft');

            $table->string('currency', 3)->default('XAF');
            $table->decimal('estimated_total', 14, 2)->default(0);

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('ordered_at')->nullable();

            // Where the request ended up, once it did. Nullable forever: most
            // requisitions in a live system have not been ordered yet.
            $table->foreignUlid('purchase_order_id')->nullable()
                ->constrained('documents')->nullOnDelete();

            $table->text('notes')->nullable();
            // users.id is a BIGINT, not a ULID. foreignUlid here passes on
            // SQLite and is rejected outright by MySQL.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'number'], 'preq_company_number_unq');
            $table->index(['company_id', 'status', 'needed_by'], 'preq_company_status_idx');
        });

        Schema::create('purchase_requisition_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('purchase_requisition_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('item_id')->nullable()->constrained()->nullOnDelete();

            // Free text as well as a catalogue link: half of what a business
            // requisitions has never been bought before and has no item row.
            $table->string('description');
            $table->decimal('quantity', 15, 3)->default(1);
            $table->string('unit')->default('unit');
            $table->decimal('estimated_unit_price', 14, 2)->default(0);
            $table->decimal('estimated_total', 14, 2)->default(0);
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['purchase_requisition_id', 'sort_order'], 'preq_lines_sort_idx');
        });

        Schema::create('rfqs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('purchase_requisition_id')->nullable()
                ->constrained()->nullOnDelete();

            $table->string('number');
            $table->string('title')->nullable();
            // draft|sent|closed|awarded|cancelled
            $table->string('status')->default('draft');

            $table->date('issued_on')->nullable();
            $table->date('closes_on')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('awarded_at')->nullable();

            $table->foreignUlid('purchase_order_id')->nullable()
                ->constrained('documents')->nullOnDelete();

            $table->text('terms')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'number'], 'rfq_company_number_unq');
            $table->index(['company_id', 'status', 'closes_on'], 'rfq_company_status_idx');
        });

        Schema::create('rfq_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('rfq_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('purchase_requisition_line_id')->nullable()
                ->constrained('purchase_requisition_lines', indexName: 'rfq_lines_preq_line_fk')
                ->nullOnDelete();
            $table->foreignUlid('item_id')->nullable()->constrained()->nullOnDelete();

            $table->string('description');
            $table->decimal('quantity', 15, 3)->default(1);
            $table->string('unit')->default('unit');
            // Deliberately no price. The whole point of asking is not knowing.
            $table->text('specification')->nullable();
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['rfq_id', 'sort_order'], 'rfq_lines_sort_idx');
        });

        Schema::create('rfq_suppliers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('rfq_id')->constrained()->cascadeOnDelete();
            // Suppliers are contacts. There is no separate supplier table and
            // there must not become one.
            $table->foreignUlid('supplier_id')->constrained('contacts')->cascadeOnDelete();

            $table->timestamp('invited_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->text('note')->nullable();

            $table->timestamps();

            // One invitation per supplier per RFQ: two rows would mean two
            // sets of "have they answered yet", and they would disagree.
            $table->unique(['rfq_id', 'supplier_id'], 'rfq_suppliers_unq');
        });

        Schema::create('supplier_quotations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('rfq_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('supplier_id')->constrained('contacts')->cascadeOnDelete();

            // The supplier's own number, not ours. Theirs is what they will
            // quote back at us on the invoice.
            $table->string('reference')->nullable();
            // received|shortlisted|awarded|rejected|withdrawn
            $table->string('status')->default('received');

            $table->date('quoted_on')->nullable();
            $table->date('valid_until')->nullable();
            $table->unsignedSmallInteger('lead_time_days')->nullable();
            $table->string('payment_terms')->nullable();

            $table->string('currency', 3)->default('XAF');
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('tax_total', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);

            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'rfq_id', 'total'], 'squote_rfq_total_idx');
            $table->index(['company_id', 'supplier_id'], 'squote_supplier_idx');
        });

        Schema::create('supplier_quotation_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('supplier_quotation_id')
                ->constrained('supplier_quotations', indexName: 'squote_lines_quote_fk')
                ->cascadeOnDelete();
            $table->foreignUlid('rfq_line_id')->nullable()
                ->constrained('rfq_lines', indexName: 'squote_lines_rfq_line_fk')
                ->nullOnDelete();
            $table->foreignUlid('item_id')->nullable()->constrained()->nullOnDelete();

            $table->string('description');
            $table->decimal('quantity', 15, 3)->default(1);
            $table->string('unit')->default('unit');
            $table->decimal('unit_price', 14, 2)->default(0);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('line_total', 14, 2)->default(0);
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['supplier_quotation_id', 'sort_order'], 'squote_lines_sort_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_quotation_lines');
        Schema::dropIfExists('supplier_quotations');
        Schema::dropIfExists('rfq_suppliers');
        Schema::dropIfExists('rfq_lines');
        Schema::dropIfExists('rfqs');
        Schema::dropIfExists('purchase_requisition_lines');
        Schema::dropIfExists('purchase_requisitions');
    }
};
