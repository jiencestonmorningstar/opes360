<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Generated business documents (Module 13) — contracts, letters,
     * certificates, minutes.
     *
     * Deliberately separate from `documents`, which is the sales ledger. These
     * share the verification and print infrastructure but nothing else: they
     * carry no money, no lines and no payment state, and folding them into the
     * same table would put a dozen always-null columns on both.
     */
    public function up(): void
    {
        Schema::create('business_documents', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();

            $table->string('template');
            $table->string('title');
            // Assigned at issue, from the same lease ledger as everything else.
            $table->string('reference')->nullable();
            $table->string('recipient')->nullable();

            // The merge answers, kept so the document can be revised while it is
            // still a draft rather than retyped.
            $table->json('fields')->nullable();
            // The composed text, frozen at issue. Kept even for drafts so a
            // later change to the template library cannot silently rewrite
            // something a business has already shown to someone.
            $table->longText('body');

            $table->string('status')->default('draft'); // draft|issued|void
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('content_hash', 64)->nullable();
            $table->foreignUlid('verification_token_id')->nullable()->constrained()->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'template']);
            $table->unique(['company_id', 'reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_documents');
    }
};
