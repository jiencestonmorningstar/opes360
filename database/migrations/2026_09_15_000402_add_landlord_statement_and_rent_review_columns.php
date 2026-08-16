<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the landlord statement and rent reviews need — additive only.
 *
 * `commission_percent` is the agency's cut of collected rent, per property,
 * nullable with no backfill: an existing property managed for no commission
 * (a landlord running their own building) stays exactly as it was.
 *
 * `expenses.property_id` is the same pin `service_tickets.property_unit_id`
 * is: the expense stays an ordinary Expense through ExpenseRecorder, this
 * only says which building it was spent on, so the landlord statement can
 * deduct it.
 *
 * `tenancy_rent_changes` is the history a rent review leaves: what the rent
 * was, what it became, from when, and why. The recurring schedule's line is
 * rewritten at review time; this table is why "what was the rent in March"
 * keeps having an answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->decimal('commission_percent', 5, 2)->nullable();
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignUlid('property_id')->nullable()
                ->constrained('properties')->nullOnDelete();
        });

        Schema::create('tenancy_rent_changes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('tenancy_id')->constrained()->cascadeOnDelete();
            $table->decimal('rent_before', 15, 2);
            $table->decimal('rent_after', 15, 2);
            $table->date('effective_on');
            $table->text('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenancy_id', 'effective_on'], 'trc_tenancy_effective_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenancy_rent_changes');

        Schema::table('expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('property_id');
        });

        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn('commission_percent');
        });
    }
};
