<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Property management for agencies and landlords — the thin layer only.
 *
 * A lease is a `contracts` row, raised through ContractLifecycle, so renewal,
 * notice deadlines and the watch come from the contracts module rather than
 * being rebuilt here. Rent is billed by an ordinary `recurring_invoices`
 * schedule, so dunning and receipts already work. A deposit held is a
 * liability posted through the one ledger — the entry ids are stored here so
 * settlement can find and answer for them, but the *money* lives in
 * journal_lines like every other franc.
 *
 * What is genuinely new is the geography: a property, its lettable units, and
 * who is in each one right now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('properties', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('address')->nullable();
            // residential | commercial | mixed | land
            $table->string('kind')->default('residential');
            // The owner the agency manages for. Nullable because a landlord
            // managing their own building IS the business — there is no
            // second party to name.
            $table->foreignUlid('landlord_contact_id')->nullable()
                ->constrained('contacts')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'kind']);
        });

        Schema::create('property_units', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('property_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            // What the unit should let for — the asking figure a vacancy is
            // measured against, not what any tenancy actually pays.
            $table->decimal('target_rent', 15, 2)->nullable();
            // vacant | occupied | unavailable
            $table->string('status')->default('vacant');
            $table->text('notes')->nullable();
            $table->timestamps();

            // "Apartment 3B" can exist once per building, not once per company.
            $table->unique(['property_id', 'label']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('tenancies', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('property_unit_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('tenant_contact_id')->constrained('contacts')->cascadeOnDelete();
            // The lease. Nullable on delete only so removing a contract does
            // not orphan the history of who lived where; a live tenancy always
            // has one, because Tenancies::start() raises it.
            $table->foreignUlid('contract_id')->nullable()
                ->constrained('contracts')->nullOnDelete();
            $table->decimal('rent', 15, 2);
            $table->decimal('deposit_amount', 15, 2)->default(0);
            /*
             * The ledger refs. The receipt entry credits the deposit
             * liability; the settlement entry clears it at move-out. Stored so
             * "where is this tenant's caution in the books" is a lookup, and
             * so settlement is idempotent — a second click finds the entry
             * already written and refuses.
             */
            $table->foreignUlid('deposit_entry_id')->nullable()
                ->constrained('journal_entries')->nullOnDelete();
            $table->foreignUlid('deposit_settlement_entry_id')->nullable()
                ->constrained('journal_entries')->nullOnDelete();
            $table->decimal('deposit_retained', 15, 2)->nullable();
            $table->text('deposit_retention_reason')->nullable();
            // The standing order that bills the rent — an ordinary schedule
            // the nightly generator and dunning already understand.
            $table->foreignUlid('recurring_invoice_id')->nullable()
                ->constrained('recurring_invoices')->nullOnDelete();
            $table->date('moved_in_on');
            $table->date('moved_out_on')->nullable();
            // active | ended
            $table->string('status')->default('active');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('ended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['property_unit_id', 'status']);
        });

        // A maintenance request IS a service ticket; this is the pin on the
        // map saying which door it is about, so a property page can list its
        // own tickets rather than everything its tenants ever phoned in.
        Schema::table('service_tickets', function (Blueprint $table) {
            $table->foreignUlid('property_unit_id')->nullable()
                ->constrained('property_units')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('service_tickets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('property_unit_id');
        });

        Schema::dropIfExists('tenancies');
        Schema::dropIfExists('property_units');
        Schema::dropIfExists('properties');
    }
};
