<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Number leases. A device offline must be able to hand a customer a receipt
         * carrying a final, non-provisional number, so blocks of numbers are leased
         * while online and consumed offline. `void_unused_from` is the audit trail
         * that explains every gap in the sequence.
         * See docs/architecture/offline-sync.md §5.
         */
        Schema::create('number_leases', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->string('document_type');
            $table->unsignedSmallInteger('year');
            $table->unsignedBigInteger('range_start');
            $table->unsignedBigInteger('range_end');
            $table->unsignedBigInteger('next_available');
            $table->foreignUlid('device_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('active'); // active|exhausted|expired|revoked
            $table->unsignedBigInteger('void_unused_from')->nullable();
            $table->timestamp('issued_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'document_type', 'year']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('documents', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            // quotation|invoice|proforma|purchase_order|delivery_note|credit_note|debit_note
            $table->string('type');
            $table->string('number')->nullable();
            $table->foreignUlid('number_lease_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('contact_id')->nullable()->constrained()->restrictOnDelete();

            // draft|issued|sent|accepted|partial|paid|void|expired
            $table->string('status')->default('draft');

            $table->date('issue_date')->nullable();
            $table->date('due_date')->nullable();
            $table->date('valid_until')->nullable();

            $table->string('currency', 3)->default('USD');
            $table->decimal('exchange_rate', 15, 6)->default(1);
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('discount_total', 15, 2)->default(0);
            $table->decimal('tax_total', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->decimal('amount_paid', 15, 2)->default(0);
            $table->decimal('balance', 15, 2)->default(0);

            $table->text('notes')->nullable();
            $table->text('terms')->nullable();
            $table->string('reference')->nullable();
            // Lineage: quotation -> invoice -> credit note.
            $table->foreignUlid('parent_document_id')->nullable()->constrained('documents')->nullOnDelete();

            // Written once at issue and never again. Paired with immutability this
            // is the whole tamper-detection mechanism.
            $table->string('content_hash')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('signature_path')->nullable();
            $table->foreignUlid('verification_token_id')->nullable()->constrained()->nullOnDelete();
            $table->string('pdf_path')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('device_id')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'type', 'number']);
            $table->index(['company_id', 'type', 'status', 'issue_date']);
            $table->index(['company_id', 'contact_id']);
            $table->index(['company_id', 'issue_date']);
        });

        Schema::create('document_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('document_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description');
            $table->decimal('quantity', 15, 3)->default(1);
            $table->string('unit')->default('unit');
            $table->decimal('unit_price', 15, 2)->default(0);
            $table->string('discount_type')->nullable(); // percent|amount
            $table->decimal('discount_value', 15, 2)->default(0);
            $table->foreignUlid('tax_rate_id')->nullable()->constrained('tax_rates')->nullOnDelete();
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('line_total', 15, 2)->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['document_id', 'sort_order']);
        });

        Schema::create('document_approvals', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action'); // submitted|approved|rejected
            $table->text('comment')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['document_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_approvals');
        Schema::dropIfExists('document_lines');
        Schema::dropIfExists('documents');
        Schema::dropIfExists('number_leases');
    }
};
