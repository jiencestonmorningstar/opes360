<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            /*
             * How many months of audit trail this business keeps, null meaning
             * the platform default. A number, not a policy table: the trail has
             * exactly one axis a business may reasonably shorten (how long
             * chatter is kept), and the floors that actually matter are code in
             * App\Support\AuditRetention where a setting cannot undercut them.
             */
            $table->unsignedSmallInteger('audit_retention_months')->nullable()->after('modules');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('audit_retention_months');
        });
    }
};
