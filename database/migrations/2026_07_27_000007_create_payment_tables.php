<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('contact_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('method'); // cash|bank_transfer|mobile_money|card
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3)->default('USD');
            $table->decimal('exchange_rate', 15, 6)->default(1);
            $table->string('reference')->nullable();
            $table->string('provider')->nullable();
            $table->string('provider_reference')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('received_at');
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('device_id')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'received_at']);
            $table->index(['company_id', 'method']);
        });

        /*
         * A payment is separate from a receipt because one payment can settle
         * several invoices, and part-payments are the normal case in this market
         * rather than an edge case.
         */
        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('payment_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('document_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->timestamps();

            $table->unique(['payment_id', 'document_id']);
            $table->index('document_id');
        });

        Schema::create('receipts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('payment_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('contact_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('number');
            $table->foreignUlid('number_lease_id')->nullable()->constrained()->nullOnDelete();
            $table->string('format')->default('thermal80'); // a4|a5|thermal58|thermal80
            $table->decimal('total', 15, 2);
            $table->string('currency', 3)->default('USD');
            $table->string('status')->default('issued'); // issued|void
            $table->string('content_hash')->nullable();
            $table->string('signature_path')->nullable();
            $table->foreignUlid('verification_token_id')->nullable()->constrained()->nullOnDelete();
            $table->string('pdf_path')->nullable();
            $table->timestamp('issued_at');
            $table->foreignId('cashier_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('device_id')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'issued_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipts');
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('payments');
    }
};
