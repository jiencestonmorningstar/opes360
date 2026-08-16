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
             * Whether confidential and restricted papers print with the
             * "CONFIDENTIEL — company · viewer · time" footer (§2.17). A
             * database default rather than a create()-time backfill, so every
             * existing and future company is on without a data migration. The
             * DRAFT/VOID status watermarks have no column here on purpose:
             * they are not a preference.
             */
            $table->boolean('prints_confidential_footer')->default(true)->after('audit_retention_months');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('prints_confidential_footer');
        });
    }
};
