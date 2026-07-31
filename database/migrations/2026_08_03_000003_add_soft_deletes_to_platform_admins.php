<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Revoking an admin soft-deletes rather than hard-deletes: platform_admins
 * -> platform_admin_activity is a cascadeOnDelete foreign key, so a hard
 * delete would wipe every action that admin ever logged — exactly the
 * accountability record the whole "full read access" design leans on.
 * Soft-deleting also blocks login for free: the Eloquent auth provider's
 * lookup query respects the SoftDeletingScope like any other query.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_admins', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('platform_admins', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
