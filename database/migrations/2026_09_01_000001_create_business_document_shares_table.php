<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_document_shares', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('business_document_id')->constrained()->cascadeOnDelete();

            // The link itself. Never the document's public verification token
            // or a signer's token — a different audience (anyone holding the
            // link, until it expires or is revoked) and a different question
            // ("may this be viewed" rather than "is this genuine" or "did you
            // sign it").
            $table->string('share_token')->unique();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('expires_at')->nullable();
            $table->string('password_hash')->nullable();
            $table->boolean('allow_download')->default(true);
            $table->timestamp('revoked_at')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'business_document_id']);
        });

        Schema::create('business_document_share_accesses', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('business_document_share_id');

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('viewed_at');

            /*
             * Named explicitly, both the foreign key and the index — the
             * auto-generated names here run to 68 and 71 characters, over
             * MySQL's 64-character identifier limit. SQLite accepted both
             * silently; see docs/HANDOVER.md for why that gap matters, and
             * why every migration this session that touched a compound name
             * has hit it.
             */
            $table->foreign('business_document_share_id', 'doc_share_access_share_fk')
                ->references('id')->on('business_document_shares')->cascadeOnDelete();
            $table->index('business_document_share_id', 'doc_share_accesses_share_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_document_share_accesses');
        Schema::dropIfExists('business_document_shares');
    }
};
