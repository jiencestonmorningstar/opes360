<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_log', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event');
            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable();
            $table->json('properties')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['company_id', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
        });

        // One row per installed PWA. Sync tokens are scoped to user + company +
        // device so a single lost phone can be revoked without touching others.
        Schema::create('devices', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('platform')->nullable();
            $table->string('token_hash')->unique();
            $table->unsignedInteger('pending_count')->default(0);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'user_id']);
        });

        // One immutable public token per verifiable subject. QR resolves it today,
        // AR resolves the same token in Phase 8 — see docs/architecture/qr-ar.md.
        Schema::create('verification_tokens', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('token', 22)->unique();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->string('subject_type');
            $table->string('subject_id');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
        });

        Schema::create('verification_scans', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('verification_token_id')->constrained()->cascadeOnDelete();
            $table->string('country', 2)->nullable();
            $table->string('region')->nullable();
            $table->string('device_class')->nullable();
            $table->string('referrer')->nullable();
            $table->timestamp('scanned_at');

            $table->index(['verification_token_id', 'scanned_at']);
        });

        // Single polymorphic media table: logos, banners, product photos, portfolio
        // images and attachments all land here, which keeps the offline blob-upload
        // path uniform instead of one path per entity.
        Schema::create('media', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->string('collection')->default('default');
            $table->string('disk')->default('public');
            $table->string('path');
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('checksum')->nullable();
            $table->string('attachable_type')->nullable();
            $table->string('attachable_id')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['attachable_type', 'attachable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
        Schema::dropIfExists('verification_scans');
        Schema::dropIfExists('verification_tokens');
        Schema::dropIfExists('devices');
        Schema::dropIfExists('activity_log');
    }
};
