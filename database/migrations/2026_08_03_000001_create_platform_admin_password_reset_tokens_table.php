<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A separate reset-token table for the 'platform_admins' broker — sharing
 * the business password_reset_tokens table would let an admin email that
 * happens to collide with a business user's email cross the two credential
 * stores, which is exactly what the rest of this system goes out of its way
 * to keep apart.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_admin_password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_admin_password_reset_tokens');
    }
};
