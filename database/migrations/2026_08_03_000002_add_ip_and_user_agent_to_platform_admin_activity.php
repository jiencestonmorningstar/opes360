<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The audit trail is the whole accountability mechanism behind giving
 * admins full read access to every business — recording only *what*
 * changed and *who* changed it, with no *where from*, was thinner than
 * that mechanism needs to be.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_admin_activity', function (Blueprint $table) {
            $table->string('ip_address', 45)->nullable()->after('meta');
            $table->string('user_agent', 512)->nullable()->after('ip_address');
        });
    }

    public function down(): void
    {
        Schema::table('platform_admin_activity', function (Blueprint $table) {
            $table->dropColumn(['ip_address', 'user_agent']);
        });
    }
};
