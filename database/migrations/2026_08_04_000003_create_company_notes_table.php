<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persistent, append-only support context on a business — separate from
 * PlatformAdminActivity, which logs discrete actions (suspend, plan
 * change). A note is just "spoke with the owner 7/30, promised payment by
 * 8/5" — nothing structured happened, so it doesn't belong in the action
 * log, but it needs to survive between admins and between sessions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_notes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('platform_admin_id')->nullable()->constrained('platform_admins')->nullOnDelete();
            $table->text('body');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_notes');
    }
};
