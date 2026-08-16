<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            /*
             * What the record was called at the moment it was touched.
             *
             * The trail exists for the argument that happens after the fact,
             * and by then the row it is about may have been deleted — at which
             * point subject_type + subject_id resolve to nothing and the screen
             * can only offer a ULID. Copying the name in at write time is the
             * only way an entry stays readable once its subject is gone, which
             * is exactly the case the log is kept for.
             */
            $table->string('subject_label')->nullable()->after('subject_id');

            /*
             * "What has this person been doing?" is the first question anyone
             * asks of an audit trail and the existing indexes cannot answer it
             * — company_id + created_at scans the whole company's history and
             * filters in memory. On a business a year in that is the difference
             * between a screen and a timeout.
             */
            $table->index(['user_id', 'created_at'], 'activity_log_actor_idx');

            // The list defaults to newest-first within an action type; without
            // this the event filter is a full scan of the company's rows.
            $table->index(['company_id', 'event', 'created_at'], 'activity_log_event_idx');
        });
    }

    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->dropIndex('activity_log_actor_idx');
            $table->dropIndex('activity_log_event_idx');
            $table->dropColumn('subject_label');
        });
    }
};
