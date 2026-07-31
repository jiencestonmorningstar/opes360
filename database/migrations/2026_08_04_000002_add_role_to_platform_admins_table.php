<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two tiers, not a permissions matrix: 'admin' (everything, including
 * managing other admins and changing plans) and 'support' (full read
 * access — the platform's own design philosophy — plus the routine
 * write actions like suspend/reactivate/member-management, but not the
 * two actions with real financial or security weight). Defaults existing
 * rows to 'admin' so nobody's access silently narrows on deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_admins', function (Blueprint $table) {
            $table->string('role')->default('admin')->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('platform_admins', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
