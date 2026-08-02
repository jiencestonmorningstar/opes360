<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which modules a business has switched off.
     *
     * Deliberately stores departures from the default rather than the full
     * list of what is on. A module added to the catalogue in a later release
     * then arrives switched on for every existing business, instead of being
     * silently missing for all of them because their stored list predates it —
     * which is the failure mode of writing the enabled set out in full, and
     * one nobody notices until a customer asks where the new feature is.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->json('modules')->nullable()->after('payroll_settings');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('modules');
        });
    }
};
