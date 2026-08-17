<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Soft edit-lock for the rich editor: who is in the document right now, and
 * when they last proved they still are. Additive only — nothing about the
 * existing draft/issued lifecycle or the manual `is_locked` freeze changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_documents', function (Blueprint $table) {
            $table->foreignId('editing_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('editing_heartbeat_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('business_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('editing_user_id');
            $table->dropColumn('editing_heartbeat_at');
        });
    }
};
