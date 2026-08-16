<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_documents', function (Blueprint $table) {
            // null until a signature round is requested. sequential|parallel.
            $table->string('signature_mode')->nullable()->after('is_locked');
        });

        Schema::create('business_document_signatures', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('business_document_id')->constrained()->cascadeOnDelete();

            // Ordering for a sequential round; ignored for a parallel one.
            $table->unsignedInteger('order')->default(1);

            $table->string('signer_name');
            $table->string('signer_email');
            // Set when the signer is a member of the business, not an
            // outside party — an employment contract signed by staff.
            $table->foreignId('signer_user_id')->nullable()->constrained('users')->nullOnDelete();

            // pending|signed|declined
            $table->string('status')->default('pending');

            // The link a signer uses, not the document's public verification
            // token — different token, different audience, different
            // lifetime. Unique across the table, the same reasoning
            // VerificationToken already uses: nobody should enumerate a
            // business's pending signatures by incrementing a URL.
            $table->string('signing_token')->unique();

            $table->timestamp('signed_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->text('declined_reason')->nullable();
            $table->string('ip_address', 45)->nullable();

            $table->timestamps();

            // Named explicitly — the auto-generated name is 72 characters,
            // over MySQL's 64-character identifier limit. SQLite accepted it
            // silently; see docs/HANDOVER.md for why that gap matters.
            $table->index(['company_id', 'business_document_id'], 'doc_signatures_company_doc_idx');
            $table->index(['business_document_id', 'order'], 'doc_signatures_doc_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_document_signatures');

        Schema::table('business_documents', function (Blueprint $table) {
            $table->dropColumn('signature_mode');
        });
    }
};
