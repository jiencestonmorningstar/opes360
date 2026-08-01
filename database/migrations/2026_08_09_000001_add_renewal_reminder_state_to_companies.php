<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which renewal reminder the owner has already received, and for which
     * renewal date. The reminder command runs daily; without this it would
     * send the same "your plan renews soon" mail every morning for a week.
     *
     * Keyed to the date rather than a boolean so the state resets itself: a
     * successful payment pushes plan_renews_at forward, the stored date no
     * longer matches, and the next cycle's reminders start clean without
     * anyone having to remember to clear a flag.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('renewal_reminder_stage')->nullable()->after('plan_renews_at'); // upcoming|overdue
            $table->date('renewal_reminder_for')->nullable()->after('renewal_reminder_stage');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['renewal_reminder_stage', 'renewal_reminder_for']);
        });
    }
};
