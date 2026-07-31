<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only, like the stock movement ledger: every point earned,
     * redeemed or adjusted is a row, never an in-place edit to a running
     * total. contacts.loyalty_points is a cached sum of this table, kept for
     * a cheap read; if the two ever disagree, this table is right.
     */
    public function up(): void
    {
        Schema::create('loyalty_transactions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('contact_id')->constrained()->cascadeOnDelete();

            $table->string('type'); // earn|redeem|adjust
            // Signed: earn/positive adjust are +, redeem/negative adjust are -.
            $table->integer('points');
            $table->unsignedInteger('balance_after');

            // What earned or redeemed it — a payment, a manual adjustment.
            $table->string('reference_type')->nullable();
            $table->string('reference_id')->nullable();
            $table->string('note')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'contact_id']);
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_transactions');
    }
};
