<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Leads: the people who might become customers, before they are anyone.
     *
     * The customer book (`contacts`) deliberately holds only real business
     * relationships — people you invoice, chase and owe. A trade-fair phone
     * number does not belong there: half of them go nowhere, and a customer
     * list padded with maybes stops being trusted. So a lead lives here until
     * it earns a Contact.
     *
     * Conversion LINKS rather than moves: `contact_id` and `deal_id` are set
     * and the lead row survives, so "where did this customer come from" stays
     * answerable, and "which sources actually produce customers" becomes a
     * one-table query.
     */
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('name');
            $table->string('company_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();

            // Free text, not a lookup table: "Marc's cousin" is a real source
            // in a small business, and a dropdown would flatten it to "other".
            $table->string('source')->nullable();

            $table->string('status')->default('new');
            $table->text('notes')->nullable();
            $table->string('lost_reason')->nullable();

            // Who is working it. users.id is BIGINT, hence foreignId. Nulled
            // rather than cascaded so a departure does not delete the funnel.
            $table->foreignId('assigned_to')->nullable()
                ->constrained('users')->nullOnDelete();

            // What the lead became. Both nullable both ways: a lead may convert
            // to a contact without a deal, and losing either later should not
            // erase the history of where it came from.
            $table->foreignUlid('contact_id')->nullable()
                ->constrained('contacts')->nullOnDelete();
            $table->foreignUlid('deal_id')->nullable()
                ->constrained('deals')->nullOnDelete();

            $table->timestamp('converted_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // The register query: one company's open leads by status.
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
