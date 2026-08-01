<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per attempt to pay for a plan tier via a mobile money provider.
     * `external_id` is the reference we hand to MTN/Orange and get back on
     * their webhook, so a payment can always be found without trusting
     * anything else in the callback payload. `payload` keeps the raw
     * request/response pairs for support and reconciliation.
     */
    public function up(): void
    {
        Schema::create('subscription_payments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('plan'); // basic|growth|business
            $table->string('billing_cycle')->default('monthly'); // monthly|annual
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3)->default('XAF');
            $table->string('provider'); // mtn_momo|orange_money
            $table->string('phone')->nullable();
            $table->string('external_id')->unique();
            $table->string('provider_reference')->nullable();
            $table->string('status')->default('pending'); // pending|successful|failed|cancelled|expired
            $table->string('failure_reason')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['provider', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_payments');
    }
};
