<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_invoices', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('contact_id')->constrained('contacts')->cascadeOnDelete();

            $table->string('name');

            /*
             * The lines as typed, not a link to a template document.
             *
             * A schedule has to keep billing the agreed amount even after
             * somebody edits or voids the invoice it was first modelled on, so
             * it owns its own copy. This is the same reason a VIP membership
             * copies its tier's price rather than reading it live.
             */
            $table->json('lines');
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->text('notes')->nullable();

            $table->string('frequency');            // weekly | monthly | quarterly | yearly
            $table->unsignedSmallInteger('interval')->default(1);

            $table->date('starts_on');
            $table->date('next_run_on');
            $table->date('ends_on')->nullable();
            $table->unsignedInteger('max_occurrences')->nullable();
            $table->unsignedInteger('occurrences')->default(0);

            $table->unsignedSmallInteger('payment_terms_days')->default(14);

            /*
             * Off by default. Issuing burns a number in a legally sequential
             * series and puts the amount on the customer's balance; doing that
             * unattended is a decision a business should make deliberately
             * rather than inherit.
             */
            $table->boolean('auto_issue')->default(false);

            $table->string('status')->default('active');   // active | paused | finished
            $table->timestamp('last_run_at')->nullable();
            $table->foreignUlid('last_document_id')->nullable()->constrained('documents')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // The sweep's only query: everything due, across every tenant.
            $table->index(['status', 'next_run_on']);
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_invoices');
    }
};
