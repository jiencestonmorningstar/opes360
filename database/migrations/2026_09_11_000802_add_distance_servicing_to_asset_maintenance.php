<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /*
     * Servicing that falls due on distance rather than on the calendar.
     *
     * Three columns on the existing maintenance row, not a second scheduler.
     * A van serviced every 10 000 km and a generator serviced every six months
     * are the same commitment measured on different clocks, and a business
     * wants one list of what is outstanding. Two tables would mean two lists,
     * two overdue counts, and an argument about which one is right.
     *
     * All three are nullable, so every job booked before today keeps behaving
     * exactly as it did: no distance terms, due on a date, and nothing else.
     */
    public function up(): void
    {
        Schema::table('asset_maintenance', function (Blueprint $table) {
            // The reading this visit falls due at.
            $table->unsignedInteger('due_at_odometer')->nullable()->after('due_on');

            // What the clock actually read when the work was carried out. The
            // next visit counts from this, not from `due_at_odometer` — a
            // service done 800 km late moves the schedule on by 800 km, in the
            // same way a late oil change resets the months.
            $table->unsignedInteger('completed_at_odometer')->nullable()->after('completed_on');

            // "Every 10 000 km". Sits beside `interval_months`; a vehicle may
            // carry both and gets whichever falls first.
            $table->unsignedInteger('interval_km')->nullable()->after('interval_months');

            // Short name given explicitly: `asset_maintenance` is already a
            // long prefix and MySQL refuses an identifier over 64 characters,
            // while SQLite accepts one and lets it break in production only.
            $table->index(['company_id', 'due_at_odometer'], 'asset_maint_company_odo_idx');
        });
    }

    public function down(): void
    {
        Schema::table('asset_maintenance', function (Blueprint $table) {
            $table->dropIndex('asset_maint_company_odo_idx');
            $table->dropColumn(['due_at_odometer', 'completed_at_odometer', 'interval_km']);
        });
    }
};
